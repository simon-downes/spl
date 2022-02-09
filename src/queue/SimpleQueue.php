<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\queue;

use stdClass;
use RuntimeException;

use spl\contracts\database\DatabaseConnection;

use spl\SPL;
use spl\contracts\queue\Job;
use spl\contracts\queue\Queue;
use spl\contracts\queue\TaskStatus;

class SimpleQueue implements Queue {

    public function __construct( protected DatabaseConnection $db, protected $table = 'queue' ) {
    }

    /**
     * Dispatch a task to the queue.
     *
     * @param string $task_type
     * @param string $name       optional name to identify a specific task
     * @param array $data
     * @return integer
     */
    public function dispatch( string $task_type, string $name, array $data ): int {

        if( empty($task_type) ) {
            throw new RuntimeException("Task type cannot be blank");
        }

        $task_id = $this->db->insert()
            ->into($this->table)
            ->item([
                'status'  => TaskStatus::QUEUED->name,
                'type'    => $task_type,
                'name'    => $name,
                'data'    => json_encode($data),
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->execute();

        if( SPL::isDebug() ) {
            error_log("QUEUED: {$task_id}\t{$task_type}\t{$name}");
        }

        return (int) $task_id;

    }

    /**
     * Return a specific task.
     *
     * @return stdClass|null
     */
    public function peek( int $task_id ): ?stdClass {

        return $this->makeTask(
            $this->db->getRow("SELECT * FROM {$this->table} WHERE id = ?", [$task_id])
        );

    }

    /**
     * Return an array of tasks in the queue, optionally filtered status and type.
     *
     * @param array $statuses     array of TaskStatus values to include
     * @param array $task_types   array of task types to include
     * @param integer $limit      maximum number of tasks to return
     * @return array
     */
    public function list( array $statuses = [], array $task_types = [], int $limit = 50 ): array {

        $q = $this->db->select('*')->from($this->table);

        if( $statuses ) {
            $q->where('status', 'IN', array_map(
                fn(TaskStatus $status) => $status->name,
                $statuses)
            );
        }

        if( $task_types ) {
            $q->where('type', 'IN', $task_types);
        }

        $q->orderBy('created', false)->limit($limit);

        return array_map([$this, 'makeTask'], $q->getAll());

    }

    /**
     * Return an associative array for each status containing the number of items, oldest item and latest item.
     *
     * @param string $type   optional task type to return the statuses for
     * @return array
     */
    public function status( string $task_type = '' ): array {

        $q = $this->db->select(
                'status',
                ['COUNT(*)', 'items'],
                ['MIN(updated)', 'oldest'],
                ['MAX(updated)', 'latest'],
            )
            ->from($this->table)
            ->groupBy(['status']);

        if( $task_type ) {
            $q->where('type', $task_type);
        }

        $data = $q->getAssoc();

        $status = [];

        foreach( TaskStatus::cases() as $case ) {
            $status[$case->name] = ($data[$case->name] ?? []) + [
                'items'  => 0,
                'oldest' => '',
                'latest' => '',
            ];
        }

        return $status;

    }

    public function clean( $before, $types = ['COMPLETE', 'KILLED', 'ERROR'] ): int {

        // remove complete,

    }

    /**
     * Transition a task from PROCESSING to ERROR and emit an error message.
     * This method should only be called by a worker.
     *
     * @param integer $task_id
     * @param string $message
     * @return boolean
     */
    public function error( int $task_id, string $message ): bool {

        $task = $this->requireTaskStatus($task_id, [TaskStatus::PROCESSING], "ERROR");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->update($this->table)
            ->set([
                'status'  => TaskStatus::ERROR->name,
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->where('id', $task->id)
            ->where('status', TaskStatus::PROCESSING->name)
            ->execute();

        error_log("ERROR: {$task->id} - {$count} updated - {$message}");

        return (bool) $count;

    }

    /**
     * Request a task be killed.
     * Tasks can be killed if they are in a QUEUED, PROCESSING, ERROR or KILL state.
     * The worker should acknowledge the kill request by calling the killAck() method.
     *
     * @param integer $task_id
     * @return boolean
     */
    public function kill( int $task_id ): bool {

        $allowed_states = [
            TaskStatus::QUEUED,
            TaskStatus::PROCESSING,
            TaskStatus::ERROR,
            TaskStatus::KILL,
        ];

        $task = $this->requireTaskStatus($task_id, $allowed_states, "KILL");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->update($this->table)
            ->set([
                'status' => TaskStatus::KILL->name,
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->where('id', $task->id)
            ->where('status', 'IN', array_map(fn(TaskStatus $status) => $status->name, $allowed_states))
            ->execute();

        error_log("KILL: {$task->id} - {$count} updated");

        return (bool) $count;

    }

    /**
     * Acknowledge that a task has been killed.
     * This method should only be called by a worker.
     *
     * @param integer $task_id
     * @return boolean
     */
    public function killed( int $task_id ): bool {

        $allowed_states = [
            TaskStatus::KILL,
            TaskStatus::KILLED,
        ];

        $task = $this->requireTaskStatus($task_id, $allowed_states, "KILLED");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->update($this->table)
            ->set([
                'status'  => TaskStatus::KILLED->name,
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->where('id', $task->id)
            ->where('status', 'IN', array_map(fn(TaskStatus $status) => $status->name, $allowed_states))
            ->execute();

        error_log("KILLED: {$task->id} - {$count} updated");

        return (bool) $count;

    }

    /**
     * Append a string to the task.
     * Only valid for tasks in the PROCESSING and KILL states.
     * This method should only be called by task handlers.
     *
     * @param integer $task_id
     * @param string $data
     * @return boolean
     */
    public function output( int $task_id, string $data ): bool {

        $allowed_states = [
            TaskStatus::PROCESSING,
            TaskStatus::KILL,
        ];

        $task = $this->requireTaskStatus($task_id, $allowed_states, "OUTPUT");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->execute(
            sprintf(
                "UPDATE {$this->table} SET output = CONCAT(output, \"%s\", \"\n\"), updated = NOW() WHERE id = ? AND status IN ('%s')",
                $data,
                implode(
                    "', '",
                    array_map(fn(TaskStatus $status) => $status->name, $allowed_states)
                )
            ),
            [
                $task->id,
            ]
        );

        return (bool) $count;

    }

    /**
     * Retry an errored task.
     *
     * @param integer $task_id
     * @return boolean
     */
    public function retry( int $task_id ): bool {

        $task = $this->requireTaskStatus($task_id, [TaskStatus::ERROR], "RETRY");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->update($this->table)
            ->set([
                'status'  => TaskStatus::QUEUED->name,
                'output'  => '',
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->where('id', $task->id)
            ->where('status', TaskStatus::ERROR->name)
            ->execute();

        error_log("RETRY: {$task->id} - {$count} updated");

        return (bool) $count;

    }

    /**
     * Attempt to grab the oldest job in the queue and return it for processing.
     * If return value is null then either no jobs are queued or the select job was
     * grabbed by another worker.
     *
     * @return Job
     */
    public function grabJob(): ?Job {

        // select oldest queued job
        // SELECT id WHERE STATUS = QUEUED ORDER BY updated ASC

        // update the status atomically
        // UPDATE WHERE id AND STATUS = QUEUED
        // SET status = PROCESSING

        // if count (i.e. record was updated) then return job

    }

// QUEUED: $id -
// KILL: $id - No such Task
// KILLED: $id -

    /**
     * Convert a database row into a task object.
     * Tasks are currently represented as instances of stdClass.
     *
     * @param array $row
     * @return stdClass|null
     */
    private function makeTask( array $row ): ?stdClass {

        if( empty($row) ) {
            return null;
        }

        $row['status'] = TaskStatus::from($row['status']);
        $row['data'] = json_decode($row['data'], true);

        return (object) $row;

    }

    /**
     * Verify that a task exists and the status is within a specific set.
     * An error is emitted if the conditions are not met.
     *
     * @param integer $id
     * @param array $statuses
     * @param string $action   The action being requested, prefixed to any error emitted
     * @return stdClass|null
     */
    private function requireTaskStatus( int $task_id, array $statuses, string $action ): ?stdClass {

        $task = $this->peek($task_id);

        if( empty($task) ) {
            error_log("{$action}: {$task_id} - No such task");
            return null;
        }

        if( !in_array($task->status, $statuses) ) {
            error_log("{$action}: {$task->id} - Invalid status: {$task->status->name}");
            return null;
        }

        return $task;

    }

}
