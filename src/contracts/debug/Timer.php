<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\debug;

/**
 * Simple timer implementation.
 */
interface Timer {

    /**
     * Start the timer.
     */
    public function start( string $label = 'start' ): void;

    /**
     * Stop the timer and add the duration to the total elapsed time.
     */
    public function stop( string $label = 'stop' ): void;

    /**
     * Mark the current point in time with the specified label.
     */
    public function mark( string $label ): void;

    /**
     * Determine if the timer is currently running.
     */
    public function isRunning(): bool;

    /**
     * Return the elapsed time from last start.
     */
    public function getElapsed(): float;

    /**
     * Return the total elapsed time.
     */
    public function getTotalElapsed(): float;

    /**
     * Return the details of the recorded marks.
     */
    public function getMarks(): array;

}
