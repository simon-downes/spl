<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\queue;

use stdClass;

interface Queue {

    /**
     * Dispatch a task to the queue.
     *
     * @param string $task_type
     * @param string $name       optional name to identify a specific task
     * @param array $data
     * @return integer           the task id
     */
    public function dispatch( string $task_type, string $name, array $data ): int;

    /**
     * Return a specific task.
     *
     * @return stdClass|null
     */
    public function peek( int $task_id ): ?stdClass;

    /**
     * Return an array of tasks in the queue, optionally filtered status and type.
     *
     * @param array $statuses     array of TaskStatus values to include
     * @param array $task_types   array of task types to include
     * @param integer $limit      maximum number of tasks to return
     * @return array
     */
    public function list( array $statuses = [], array $task_types = [], int $limit = 50 ): array;

    /**
     * Return an associative array for each status containing the number of items, oldest item and latest item.
     *
     * @param string $type   optional task type to return the statuses for
     * @return array
     */
    public function status( string $task_type = '' ): array;

    /**
     * Remove complete and optionally failed jobs before the specified date/time.
     *
     * @param string|integer $before an integer timestamp or string date time
     * @param boolean $include_failed
     * @return integer  the number of tasks that were cleaned
     */
    public function clean( string|int $before, bool $include_failed = true ): int;

    /**
     * Mark any tasks that haven't been updated since the specified time as FAILED.
     *
     * @param string|integer $before
     * @return integer   the number of tasks that were failed
     */
    public function dead( string|int $before ): int;

    /**
     * Attempt to grab the oldest job in the queue and return it for processing.
     * If return value is null then either no jobs are queued or the select job was
     * grabbed by another worker.
     *
     * @return stdClass|null
     */
    public function grab( int|string $worker_id ): ?stdClass;

    /**
     * Mark a task as COMPLETE - this method should only be called by a worker.
     *
     * @param integer $task_id
     * @return boolean
     */
    public function complete( int $task_id ): bool;

    /**
     * Mark a task as FAILED - this method should only be called by a worker.
     *
     * @param integer $task_id
     * @return boolean
     */
    public function failed( int $task_id ): bool;

    /**
     * Append a string to the task.
     * Only valid for tasks in the PROCESSING and KILL states.
     * This method should only be called by task handlers.
     *
     * @param integer $task_id
     * @param string $data
     * @return boolean
     */
    public function output( int $task_id, string $data ): bool;

}
