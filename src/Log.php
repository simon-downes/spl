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
 * Log messages can be directed to error_log() or a file. The destination is, in order: the $file
 * argument, the file set with setFile() (SPL::init() sets it from APP_LOG_FILE, resolved against
 * SPL_ROOT), or the raw APP_LOG_FILE environment variable. An empty destination or 'php' means
 * error_log(). Missing directories are created before writing to a file; if that fails an
 * E_USER_WARNING gives the reason and the message isn't written.
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
     * The default log destination; null means use the APP_LOG_FILE environment variable.
     */
    protected static ?string $file = null;

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
     * @param string $file Use 'php' to direct output to error_log(), empty for the default
     *                     destination (see setFile()), or specify a custom file path
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

        $file = static::destination($file);

        if (static::isErrorLog($file)) {
            error_log(trim($output));
            return;
        }

        // create the directory if needed - @ because another request may create it at the same time
        $dir = dirname($file);
        if (!is_dir($dir)) {

            error_clear_last();

            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                // report why, as file_put_contents() would only say "No such file or directory"
                $reason = error_get_last()['message'] ?? 'unknown error';
                trigger_error("Unable to create log directory {$dir}: {$reason}", E_USER_WARNING);
                return;
            }

        }

        file_put_contents($file, $output, FILE_APPEND | LOCK_EX);

    }

    /**
     * Sets the default log destination used when no file is passed to a log method.
     *
     * Use 'php' or an empty string for error_log(), or null to fall back to APP_LOG_FILE.
     */
    public static function setFile(?string $file): void {

        static::$file = $file;

    }

    /**
     * Checks if a message at the given level would be written with error_log().
     *
     * True when the level passes APP_LOG_LEVEL and the default destination is error_log().
     *
     * @internal Used by SPL::report() to avoid printing an error twice in the CLI.
     */
    public static function usesErrorLog(string $level = 'INFO'): bool {

        return static::shouldLog(strtoupper($level)) && static::isErrorLog(static::destination());

    }

    /**
     * Returns the destination for a message: the given file, the file set with setFile(),
     * or APP_LOG_FILE.
     */
    protected static function destination(string $file = ''): string {

        return $file ?: (static::$file ?? (string) env('APP_LOG_FILE', ''));

    }

    /**
     * Checks if a destination means error_log(): empty or 'php'.
     *
     * @internal Used by SPL::init() so it doesn't resolve these values as paths.
     */
    public static function isErrorLog(string $file): bool {

        return empty($file) || ($file == 'php');

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
