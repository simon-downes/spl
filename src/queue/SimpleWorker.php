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

use spl\contracts\queue\Queue;
use spl\contracts\queue\Worker;

class SimpleWorker implements Worker {

    /**
     *  Set a maximum execution time (in seconds) in order to avoid any potential memory leaks
     */
    protected const MAX_EXECUTION_TIME = 100;

    /**
     * Seconds between calls to check the queue for waiting tasks.
     */
    protected const INTERVAL = 1;

    /**
     * Process ID of the worker.
     */
    protected int $pid = 0;

    /**
     * Process ID of current task.
     * null - no task process is currently running
     * 0 - We're the task process that's currently running
     * >0 - We're the worker that's responsible for the currently running task
     */
    protected ?int $task_pid = null;

    /**
     * Timestamp that the worker started running.
     * Used to calculate execution time.
     */
    protected float $started = 0;

    /**
     * Has a shutdown been requested?
     */
    protected bool $shutdown = false;

    public function __construct( protected ContainerInterface $container, protected Queue $queue ) {
    }

    /**
     * The main execution loop of the worker.
     *
     * @return void
     */
    public function run(): void {

        pcntl_async_signals(true);

        // register signal handlers
        // https://www.baeldung.com/linux/sigint-and-other-termination-signals
        // see signalHandler() for details on how we handle these
        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        pcntl_signal(SIGQUIT, [$this, 'signalHandler']);
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);

        $this->pid = getmypid();

        $this->log("STARTED: Worker {$this->pid}");

        $this->started = microtime(true);

        while( true ) {

            if( $this->shouldQuit() ) {
                break;
            }

            $task = $this->queue->grab($this->pid);

            // no task waiting so go to sleep for a bit before trying again
            if( empty($task) ) {
                $this->log("TICK: Worker {$this->pid}");
                sleep(static::INTERVAL);
                continue;
            }

            // fork so we have a dedicated process for executing the task
            $this->task_pid = pcntl_fork();

            // technically we could just execute the task in the worker process
            // but let's do things properly...
            if( $this->task_pid === -1) {
                throw new RuntimeException('Unable to fork task process');
            }

            // parent and child should reconnect to the database
            $this->queue->reconnect();

            // no PID so we're the child - execute the task then exit
            if( $this->task_pid == 0 ) {

                // ignore any calls stop - except SIGKILL obvs
                pcntl_signal(SIGINT, SIG_IGN);
                pcntl_signal(SIGQUIT, SIG_IGN);
                pcntl_signal(SIGTERM, SIG_IGN);

                $this->executeTask($task);

                exit(0);

            }

            $this->queue->log("EXECUTING: #{$task->id} with PID {$this->task_pid}");

            // tell the queue to reconnect to the database
            $this->queue->reconnect();

            // wait until the task process ends
            while( pcntl_wait($status, WNOHANG) === 0) {
                // make sure we can still receive and process signals
                pcntl_signal_dispatch();
                // have a nap so we're not just spinning the cpu
                sleep(1);
            }

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

            $this->task_pid = null;

        }

        $this->log("STOPPED: Worker {$this->pid}");

    }

    /**
     * Shutdown the worker once the current task has finished.
     *
     * @param string $reason   the reason for the shutdown
     * @return void
     */
    public function shutdown( $reason = '' ): void {

        $this->shutdown = true;
        $this->log("SHUTDOWN: Worker {$this->pid} - {$reason}");

    }

    /**
     * Handler for the various termination signals.
     * SIGINT - shutdown after current task finishes
     * SIGQUIT - same as SIGINT for first, second signal terminates current task
     * SIGTERM - always terminates current task
     *
     * @param integer $signo
     * @param mixed $siginfo
     * @return void
     */
    public function signalHandler( int $signo, mixed $siginfo ) {

        // nice string names for the signals
        static $signals = [
            SIGINT  => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
            SIGTERM => 'SIGTERM',
        ];

        // count the number of times we've received each signal
        static $counts = [
            SIGINT  => 0,
            SIGQUIT => 0,
            SIGTERM => 0,
        ];

        // increment the signal count
        $counts[$signo]++;

        // we're always going to shutdown nicely
        $this->shutdown("{$signals[$signo]}[{$counts[$signo]}]");

        // if we receive SIGTERM or multiple SIGQUIT's then we'll also kill any child task process that might be running
        $kill_task = ($signo == SIGTERM) || (($signo == SIGQUIT) && ($counts[SIGQUIT] > 1));

        if( $kill_task && $this->task_pid ) {
            posix_kill($this->task_pid, SIGKILL);
            $this->log("KILLED: Task process {$this->task_pid}");
            $this->task_pid = null;
        }

    }

    /**
     * Instantiate the handler for the task type and pass the task to the handler.
     *
     * @param stdClass $task
     * @return void
     */
    protected function executeTask( stdClass $task ) {

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

    /**
     * Determine if the worker should quit on the next loop iteration.
     */
    protected function shouldQuit(): bool {

        // we should quit if we've received a termination signal
        // or we've reached the maximum execution time
        return $this->shutdown || ((microtime(true) - $this->started) > static::MAX_EXECUTION_TIME);

    }

    protected function log( string $message, int|string $level = 'info' ): void {

        // TODO: PSR-3 logging
        error_log(date('Y-m-d H:i:s'). " ". $message);

    }

}
