<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\debug;

use RuntimeException;

use spl\contracts\debug\Timer;

class BasicTimer implements Timer {

    /**
     * Time last started.
     */
    protected float $started = 0;

    /**
     * Total elapsed time.
     */
    protected float $elapsed = 0;

    /**
     * Labelled points in time.
     */
    protected array $marks = [];

    public function __construct( float $epoch = null ) {

        // epoch is either the value specified, the request time or the current time
        $epoch = $epoch ?: $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        $this->marks[] = ['epoch', (float) $epoch];

    }

    public function start( string $label = 'start' ): void {

        if( !$this->started ) {
            $this->started = microtime(true);
            $this->marks[] = [$label, $this->started];
        }

    }

    public function stop( string $label = 'stop' ): void {

        if( $this->started ) {
            $now = microtime(true);
            $this->elapsed += $now - $this->started;
            $this->started = 0;
            $this->marks[] = [$label, $now];
        }

    }

    public function mark( string $label ): void {
        $this->marks[] = [$label, microtime(true)];
    }

    public function isRunning(): bool {
        return (bool) $this->started;
    }

    public function getElapsed(): float {
        return $this->started ? (microtime(true) - $this->started) : 0;
    }

    public function getTotalElapsed(): float {

        $elapsed = $this->elapsed;

        // currently running so add on the time since the last start
        if( $this->started ) {
            $elapsed += (microtime(true) - $this->started);
        }

        return $elapsed;

    }

    public function getMarks(): array {

        $marks = [];

        $epoch = $this->marks[0][1];

        foreach( $this->marks as $i => $mark ) {

            list($label, $time) = $mark;

            $marks[] = (object) [
                'label'   => $label,
                'step'    => ($i > 0) ? $time - $this->marks[$i  -1][1] : 0,
                'elapsed' => $time - $epoch,
            ];

        }

        return $marks;

    }

}
