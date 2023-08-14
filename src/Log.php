<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl;

use BadMethodCallException;

/**
 * Simple logger.
 */
class Log {

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

    public static function __callStatic( $name, $arguments ) {

        $level = strtoupper($name);

        if( !isset( static::LEVELS[$level] ) ) {
            throw new BadMethodCallException(sprintf("Unknown method %s::%s", __CLASS__, $name));
        }

        static::message($arguments[0] ?? '', $level, $arguments[1] ?? '');

    }

    public static function message( string $message, string $level = "INFO", string $file = '' ): void {

        $level = strtoupper($level);

        // do we output logs at the current level
        if( !static::shouldLog($level) ) {
            return;
        }

        // each line is prefixed with the data, reequest id and level
        $prefix = date("Y-m-d H:i:s"). ' '. (defined('SPL_REQUEST_ID') ? SPL_REQUEST_ID : ''). ' ['. $level. ']';

        // multi-line log messages have all lines prefixed
        $output = '';
        foreach( explode("\n", $message) aS $line ) {
            $output .= "{$prefix} {$line}\n";
        }

        $file = $file ?: env('APP_LOG_FILE');

        if( empty($file) || ($file == 'php') ) {
            error_log(trim($output));
            return;
        }

        file_put_contents($file, $output, FILE_APPEND | LOCK_EX);

    }

    protected static function shouldLog( string $level ): bool {

        static $app_level = null;

        if( !$app_level ) {
            $app_level = static::LEVELS[strtoupper(env('APP_LOG_LEVEL', ''))] ?? static::LEVELS["INFO"];
        }

        $this_level = static::LEVELS[$level] ?? static::LEVELS["INFO"];

        return $this_level >= $app_level;
    }

}
