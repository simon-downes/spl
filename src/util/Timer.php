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
     * Time last started.
     *
     * @var float
     */
    protected static float $started = 0;

    /**
     * Total elapsed time.
     *
     * @var float
     */
    protected static float $elapsed = 0;

    /**
     * Labelled points in time.
     *
     * @var array<int, array{0: string, 1: float}>
     */
    protected static array $marks = [];

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Get the time elapsed since the request started.
     *
     * @return float The time elapsed in seconds, or -1 if the request start time is not available
     */
    public static function requestTime(): float {

        $epoch = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('SPL_START_TIME') ? SPL_START_TIME : 0);

        return $epoch ? microtime(true) - $epoch : -1;

    }

    /**
     * Start the timer.
     *
     * @param string $label The label for this start point
     * 
     * @return void
     */
    public static function start(string $label = 'start'): void {

        if (!static::$started) {
            static::$started = microtime(true);
            static::$marks[] = [$label, static::$started];
        }

    }

    /**
     * Stop the timer.
     *
     * @param string $label The label for this stop point
     * 
     * @return void
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
     * Reset the timer.
     *
     * @return void
     */
    public static function reset(): void {
        static::$started = 0;
        static::$elapsed = 0;
        static::$marks   = [];
    }

    /**
     * Mark a point in time.
     *
     * @param string $label The label for this mark
     * 
     * @return void
     */
    public static function mark(string $label): void {
        static::$marks[] = [$label, microtime(true)];
    }

    /**
     * Check if the timer is running.
     *
     * @return bool True if the timer is running
     */
    public static function isRunning(): bool {
        return (bool) static::$started;
    }

    /**
     * Get the elapsed time since the timer was started.
     *
     * @return float The elapsed time in seconds
     */
    public static function getElapsed(): float {
        return static::$started ? (microtime(true) - static::$started) : 0;
    }

    /**
     * Get the total elapsed time.
     *
     * @return float The total elapsed time in seconds
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
     * Get all the marked points in time.
     *
     * @return array<int, object> An array of mark objects with properties:
     *                           - label: string
     *                           - step: float (time since previous mark)
     *                           - elapsed: float (time since first mark)
     *                           - since_epoch: float (time since request start)
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
