<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\queue;

use stdClass;
use RuntimeException;

use spl\contracts\database\DatabaseConnection;

use spl\contracts\queue\Queue;
use spl\contracts\queue\TaskStatus;

class SimpleQueue implements Queue {

    public function __construct( protected DatabaseConnection $db, protected $table = 'queue' ) {
    }

    public function dispatch( string $task_type, string $name, array $data ): int {

        if( empty($task_type) ) {
            throw new RuntimeException("Task type cannot be blank");
        }

        $task_id = $this->db->insert()
            ->into($this->table)
            ->item([
                'status'  => TaskStatus::QUEUED->value,
                'type'    => $task_type,
                'name'    => $name,
                'data'    => json_encode($data),
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->execute();

        $this->log("QUEUED: {$task_id}\t{$task_type}\t{$name}");

        return (int) $task_id;

    }

    public function peek( int $task_id ): ?stdClass {

        return $this->makeTask(
            $this->db->getRow("SELECT * FROM {$this->table} WHERE id = ?", [$task_id])
        );

    }

    public function list( array $statuses = [], array $task_types = [], int $limit = 50 ): array {

        $q = $this->db->select('*')->from($this->table);

        if( $statuses ) {
            $q->where('status', 'IN', array_map(
                fn(TaskStatus $status) => $status->value,
                $statuses)
            );
        }

        if( $task_types ) {
            $q->where('type', 'IN', $task_types);
        }

        $q->orderBy('created', false)->limit($limit);

        return array_map([$this, 'makeTask'], $q->getAll());

    }

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
            $status[$case->value] = ($data[$case->value] ?? []) + [
                'items'  => 0,
                'oldest' => '',
                'latest' => '',
            ];
        }

        return $status;

    }

    public function clean( string|int $before, bool $include_failed = true ): int {

        if( is_int($before) ) {
            $before = date('Y-m-d H:i:s', $before);
        }

        $statuses = [
            TaskStatus::COMPLETE->value
        ];

        if( $include_failed ) {
            $statuses[] = TaskStatus::FAILED->value;
        }

        $count = (int) $this->db->delete()
            ->from($this->table)
            ->where('updated', '<=' , $before)
            ->where('status', 'IN' , $statuses)
            ->execute();

        $this->log("CLEANED: {$count}");

        return $count;

    }

    public function dead( string|int $before ): int {

        if( is_int($before) ) {
            $before = date('Y-m-d H:i:s', $before);
        }

        $count = (int) $this->db->execute(
            "UPDATE {$this->table}
                SET status = :new_status,
                    output = CONCAT(output, :output),
                    updated = NOW()
              WHERE status = :current_status
                AND updated <= :before",
            [
                'new_status'     => TaskStatus::FAILED->value,
                'output'         => "DEAD\n",
                'current_status' => TaskStatus::PROCESSING->value,
                'before'         => $before,
            ]
        );

        $this->log("DEAD: {$count}");

        return $count;

    }


    public function complete( int $task_id ): bool {

        return $this->transitionState($task_id, TaskStatus::COMPLETE, TaskStatus::PROCESSING);

    }

    public function failed( int $task_id ): bool {

        return $this->transitionState($task_id, TaskStatus::FAILED, TaskStatus::PROCESSING);

    }

    public function output( int $task_id, string $data ): bool {

        $task = $this->requireTaskStatus($task_id, [TaskStatus::PROCESSING], "OUTPUT");

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->execute(
            sprintf(
                "UPDATE {$this->table} SET output = CONCAT(output, \"%s\"), updated = NOW() WHERE id = ?",
                $data. "\n"
            ),
            [
                $task->id,
            ]
        );

        return (bool) $count;

    }

    public function grab( int|string $worker_id ): ?stdClass {

        // grab the id of the oldest task with the QUEUED status
        $task_id = (int) $this->db->getOne("SELECT id FROM {$this->table} WHERE status = ? ORDER BY updated ASC LIMIT 1", [TaskStatus::QUEUED->value]);

        if( !$task_id ) {
            return null;
        }

        // update the task status to PROCESSING
        // we include a check on where the status is QUEUED to ensure the task hasn't been grabbed by another worker
        $grabbed = (int) $this->db->execute(
            "UPDATE {$this->table} SET status = ? WHERE id = ? AND status = ?",
            [
                TaskStatus::PROCESSING->value,
                $task_id,
                TaskStatus::QUEUED->value,
            ]
        );

        if( !$grabbed ) {
            $this->log("SKIPPED: #{$task_id} - Worker {$worker_id}");
            return null;
        }

        $this->log("PROCESSING: #{$task_id} - Worker {$worker_id}");

        return $this->peek($task_id);

    }

    /**
     * Reconnect to the database.
     * This method is called by workers after a task process has been forked in order to create a new file descriptor.
     * https://www.php.net/manual/en/function.pcntl-fork.php#70721
     *
     * @return boolean
     */
    public function reconnect(): void {
        $this->db->reconnect();
    }

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
     * Transition a task to another status from a set of expected statuses.
     *
     * @param int $task_id
     * @param TaskStatus $new_status
     * @param array|TaskStatus $expected_status
     * @return boolean
     */
    protected function transitionState( int $task_id, TaskStatus $new_status, array|TaskStatus $expected_status ): bool {

        if( !is_array($expected_status) ) {
            $expected_status = [$expected_status];
        }

        $task = $this->requireTaskStatus($task_id, $expected_status, $new_status->value);

        if( empty($task) ) {
            return false;
        }

        $count = $this->db->update($this->table)
            ->set([
                'status' => $new_status->value,
                'updated' => date('Y-m-d H:i:s'),
            ])
            ->where('id', $task->id)
            ->where('status', 'IN', array_map(fn(TaskStatus $status) => $status->value, $expected_status))
            ->execute();

        $this->log("{$new_status->value}: #{$task->id} - ". ($count ? 'OK' : 'Update failed'));

        return (bool) $count;

    }

    /**
     * Verify that a task exists and the status is within a specific set.
     * An error is emitted if the conditions are not met.
     *
     * @param integer $id
     * @param array $statuses
     * @param string $action   The new status being requested, prefixed to any error emitted
     * @return stdClass|null
     */
    private function requireTaskStatus( int $task_id, array $statuses, string $action ): ?stdClass {

        $task = $this->peek($task_id);

        if( empty($task) ) {
            $this->log("{$action}: #{$task_id} - No such task");
            return null;
        }

        if( !in_array($task->status, $statuses) ) {
            $this->log("{$action}: #{$task->id} - Invalid status: {$task->status->value}");
            return null;
        }

        return $task;

    }

    public function log( string $message, int|string $level = 'info' ): void {

        // TODO: PSR-3 logging
        error_log(date('Y-m-d H:i:s'). " ". $message);

    }

}
