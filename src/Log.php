<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use BadMethodCallException;

/**
 * Simple logger.
 * 
 * Provides a simple logging interface with support for different log levels.
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
     * Handle magic static method calls for different log levels.
     *
     * @param string $name      The method name (debug, info, warning, error, critical)
     * @param array  $arguments The arguments passed to the method
     * 
     * @return void
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
     * Log a message with the specified level.
     *
     * @param string $message The message to log
     * @param string $level   The log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     * @param string $file    The file to log to (empty for default, 'php' for error_log())
     * 
     * @return void
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
     * Determine if a message with the given level should be logged.
     *
     * @param string $level The log level to check
     * 
     * @return bool True if the message should be logged, false otherwise
     */
    protected static function shouldLog(string $level): bool {

        static $app_level = null;

        if (!$app_level) {
            $app_level = static::LEVELS[strtoupper((string) env('APP_LOG_LEVEL', ''))] ?? static::LEVELS["INFO"];
        }

        $this_level = static::LEVELS[$level] ?? static::LEVELS["INFO"];

        return $this_level >= $app_level;
    }

}
