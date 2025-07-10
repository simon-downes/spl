<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use RuntimeException;

/**
 * Timer utility class.
 *
 * Provides methods for timing operations and measuring elapsed time.
 */
class Timer {

    /**
     * Timestamp when the timer was last started.
     */
    protected static float $started = 0;

    /**
     * Total elapsed time in seconds.
     */
    protected static float $elapsed = 0;

    /**
     * Labelled points in time for performance tracking.
     *
     * @var array<int, array{0: string, 1: float}>
     */
    protected static array $marks = [];

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Returns the time elapsed since the request started.
     *
     * Uses REQUEST_TIME_FLOAT or SPL_START_TIME if available.
     */
    public static function requestTime(): float {

        $epoch = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('SPL_START_TIME') ? SPL_START_TIME : 0);

        return $epoch ? microtime(true) - $epoch : -1;

    }

    /**
     * Starts the timer.
     *
     * Resets elapsed time to zero and begins timing.
     */
    public static function start(string $label = 'start'): void {

        if (!static::$started) {
            static::$started = microtime(true);
            static::$marks[] = [$label, static::$started];
        }

    }

    /**
     * Stops the timer and adds elapsed time to the total.
     */
    public static function stop(string $label = 'stop'): void {

        if (static::$started) {
            $now = microtime(true);
            static::$elapsed += $now - static::$started;
            static::$started = 0;
            static::$marks[] = [$label, $now];
        }

    }

    /**
     * Resets the timer and clears all marks.
     */
    public static function reset(): void {
        static::$started = 0;
        static::$elapsed = 0;
        static::$marks   = [];
    }

    /**
     * Records a named point in time for performance tracking.
     */
    public static function mark(string $label): void {
        static::$marks[] = [$label, microtime(true)];
    }

    /**
     * Checks if the timer is currently running.
     */
    public static function isRunning(): bool {
        return (bool) static::$started;
    }

    /**
     * Returns the time elapsed since the timer was started.
     */
    public static function getElapsed(): float {
        return static::$started ? (microtime(true) - static::$started) : 0;
    }

    /**
     * Returns the total accumulated time.
     */
    public static function getTotalElapsed(): float {

        $elapsed = static::$elapsed;

        // currently running so add on the time since the last start
        if (static::$started) {
            $elapsed += (microtime(true) - static::$started);
        }

        return $elapsed;

    }

    /**
     * Returns all recorded time marks with timing information.
     *
     * @return array<int, object> An array of mark objects with properties:
     *                           - label: string - The mark name
     *                           - step: float - Time since previous mark in seconds
     *                           - elapsed: float - Time since first mark in seconds
     *                           - since_epoch: float - Time since request start in seconds
     */
    public static function getMarks(): array {

        $marks = [];

        $start = static::$marks[0][1];

        // time since the request started or if not available, the last start time
        $epoch = $_SERVER['REQUEST_TIME_FLOAT'] ?? $start;

        foreach (static::$marks as $i => $mark) {

            list($label, $time) = $mark;

            $marks[] = (object) [
                'label'   => $label,
                'step'    => ($i > 0) ? $time - static::$marks[$i  - 1][1] : 0,
                'elapsed' => $time - $start,
                'since_epoch' => $time - $epoch,
            ];

        }

        return $marks;

    }

}
