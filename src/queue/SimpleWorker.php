<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\queue;

use stdClass;
use Throwable;
use RuntimeException;

use Psr\Container\ContainerInterface;

use spl\contracts\database\DatabaseConnection;

use spl\SPL;
use spl\contracts\queue\Queue;
use spl\contracts\queue\TaskStatus;
use spl\contracts\queue\Worker;

class SimpleWorker implements Worker {

    protected const MAX_EXECUTION_TIME = 100;

    protected const INTERVAL = 1;

    protected int $task_pid = 0;

    public function __construct( protected ContainerInterface $container, protected $queue ) {
    }

    protected function log( string $message, int|string $level = 'info' ): void {

        // TODO: PSR-3 logging
        error_log(date('Y-m-d H:i:s'). " ". $message);

    }

    public function run(): void {

        $pid = getmypid();

        $this->log("STARTED: Worker {$pid}");

        $started = microtime(true);

        while( true ) {

            // we set a maximum execution time in order to avoid any potential memory leaks
            if( (microtime(true) - $started) > static::MAX_EXECUTION_TIME ) {
                break;
            }

            $task = $this->queue->grab($pid);

            // no task waiting so go to sleep for a bit before trying again
            if( empty($task) ) {
                $this->log("TICK: Worker {$pid}");
                sleep(static::INTERVAL);
                continue;
            }

            $this->queue->reconnect();


            // fork so we have a dedicated process for executing the task
            $this->task_pid = pcntl_fork();

            // technically we could just execute the task in the worker process
            // but let's do things properly...
            if( $this->task_pid === -1) {
                throw new RuntimeException('Unable to fork task process');
            }

            // no PID so we're the child - execute the task then exit
            if( $this->task_pid == 0 ) {

                $this->execute($task);
                exit(0);

            }

            $this->queue->log("EXECUTING: #{$task->id} with PID {$this->task_pid}");

            // if we're still here then we're the worker process

            // wait until the task process ends
            while( pcntl_wait($status, WNOHANG) === 0) {
                // make sure we can still receive and process signals
                pcntl_signal_dispatch();
                // have a nap so we're not just spinning the cpu
                sleep(1);
            }

            // tell the queue to reconnect to the database
            $this->queue->reconnect();

            // did the task process exit normally?
            if( pcntl_wifexited($status) ) {
                // did the task process exit with an error code?
                if( ($exit_status = pcntl_wexitstatus($status)) !== 0) {
                    $this->queue->log("ERROR: #{$task->id} - Process exited with code {$exit_status}");
                    $this->queue->output($task->id, "Task process exited with code {$exit_status}");
                    $this->queue->failed($task->id);
                }
            }
            else {
                $this->queue->log("ERROR: #{$task->id} - Process exited abnormally");
                $this->queue->output($task->id, "Task process exited abnormally");
                $this->queue->failed($task->id);
            }

        }

        $this->log("STOPPED: Worker {$pid}");

    }

    protected function execute( stdClass $task ) {

        try {

            $handler = $this->container->get(
                $this->container->get('config')->get("tasks.{$task->type}.handler")
            );

            $handler->handle($task, $this->queue);

            $this->queue->complete($task->id);

        }
        catch( Throwable $e ) {

            $this->queue->log(sprintf(
                "ERROR: #$task->id - [%s] %s. %s:%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            $this->queue->output($task->id, (string) $e);

            $this->queue->failed($task->id);

        }

    }

}
