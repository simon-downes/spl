<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use BadMethodCallException;

/**
 * Simple logging system with support for multiple log levels.
 *
 * Log messages can be directed to error_log() or a file.
 * Log level can be controlled via APP_LOG_LEVEL environment variable.
 *
 * @method static void debug(string $message, string $file = '') Log a debug message
 * @method static void info(string $message, string $file = '') Log an info message
 * @method static void warning(string $message, string $file = '') Log a warning message
 * @method static void error(string $message, string $file = '') Log an error message
 * @method static void critical(string $message, string $file = '') Log a critical message
 */
class Log {

    /**
     * Log level constants with their numeric priority.
     *
     * Higher numbers indicate higher priority.
     */
    protected const LEVELS = [
        "DEBUG"     => 100,
        "INFO"      => 200,
        "WARNING"   => 300,
        "ERROR"     => 400,
        "CRITICAL"  => 500,
    ];

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Handles debug(), info(), warning(), error(), and critical() static methods.
     *
     * @throws BadMethodCallException If the method name is not a valid log level
     */
    public static function __callStatic(string $name, array $arguments): void {

        $level = strtoupper($name);

        if (!isset(static::LEVELS[$level])) {
            throw new BadMethodCallException(sprintf("Unknown method %s::%s", __CLASS__, $name));
        }

        static::message($arguments[0] ?? '', $level, $arguments[1] ?? '');

    }

    /**
     * Logs a message with the specified level.
     *
     * Each line is prefixed with timestamp, request ID, and level.
     *
     * @param string $file Use 'php' to direct output to error_log(), empty for APP_LOG_FILE,
     *                     or specify a custom file path
     */
    public static function message(string $message, string $level = "INFO", string $file = ''): void {

        $level = strtoupper($level);

        // do we output logs at the current level
        if (!static::shouldLog($level)) {
            return;
        }

        // each line is prefixed with the data, reequest id and level
        $prefix = date("Y-m-d H:i:s") . ' ' . (defined('SPL_REQUEST_ID') ? SPL_REQUEST_ID : '') . ' [' . $level . ']';

        // multi-line log messages have all lines prefixed
        $output = '';
        foreach (explode("\n", $message) as $line) {
            $output .= "{$prefix} {$line}\n";
        }

        $file = $file ?: (string) env('APP_LOG_FILE');

        if (empty($file) || ($file == 'php')) {
            error_log(trim($output));
            return;
        }

        file_put_contents($file, $output, FILE_APPEND | LOCK_EX);

    }

    /**
     * Checks if a message should be logged based on configured log level.
     *
     * Uses APP_LOG_LEVEL environment variable with fallback to INFO level.
     * Messages are logged if their level is >= the configured level.
     */
    protected static function shouldLog(string $level): bool {
        // Get the current app log level from environment (no caching)
        $app_level_name = strtoupper((string) env('APP_LOG_LEVEL', 'INFO'));
        $app_level = static::LEVELS[$app_level_name] ?? static::LEVELS["INFO"];

        $this_level = static::LEVELS[$level] ?? static::LEVELS["INFO"];

        return $this_level >= $app_level;
    }

}
