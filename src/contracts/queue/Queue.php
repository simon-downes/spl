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
     * Transition a task from PROCESSING to ERROR and emit an error message.
     * This method should only be called by a worker.
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
