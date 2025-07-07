<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use RuntimeException;

class Timer {

    /**
     * Time last started.
     */
    protected static float $started = 0;

    /**
     * Total elapsed time.
     */
    protected static float $elapsed = 0;

    /**
     * Labelled points in time.
     */
    protected static array $marks = [];

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    public static function requestTime(): float {

        $epoch = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('SPL_START_TIME') ? SPL_START_TIME : 0);

        return $epoch ? microtime(true) - $epoch : -1;

    }

    public static function start(string $label = 'start'): void {

        if (!static::$started) {
            static::$started = microtime(true);
            static::$marks[] = [$label, static::$started];
        }

    }

    public static function stop(string $label = 'stop'): void {

        if (static::$started) {
            $now = microtime(true);
            static::$elapsed += $now - static::$started;
            static::$started = 0;
            static::$marks[] = [$label, $now];
        }

    }

    public static function reset(): void {
        static::$started = 0;
        static::$elapsed = 0;
        static::$marks   = [];
    }

    public static function mark(string $label): void {
        static::$marks[] = [$label, microtime(true)];
    }

    public static function isRunning(): bool {
        return (bool) static::$started;
    }

    public static function getElapsed(): float {
        return static::$started ? (microtime(true) - static::$started) : 0;
    }

    public static function getTotalElapsed(): float {

        $elapsed = static::$elapsed;

        // currently running so add on the time since the last start
        if (static::$started) {
            $elapsed += (microtime(true) - static::$started);
        }

        return $elapsed;

    }

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
