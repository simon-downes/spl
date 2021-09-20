<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\helpers;

use DateTimeInterface , LogicException;

class DateTimeHelper {

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Convert a value to a timestamp.
     */
    public static function makeTimestamp( int|string|DateTimeInterface $time ): int {

        if( is_numeric($time) ) {
            return (int) $time;
        }
        elseif( $time instanceof DateTimeInterface ) {
            return $time->getTimestamp();
        }

        $ts = strtotime($time);

        if( $ts === false ) {
            throw new LogicException("Unable convert {$time} to a valid timestamp");
        }

        return $ts;

    }

    /**
     * Converts a string representation containing one or more of hours, minutes and seconds into a total number of seconds.
     * e.g. seconds("3 hours 4 minutes 10 seconds"), seconds("5min"), seconds("4.5h")
     */
    public static function seconds( string $str ): int {

        $hours   = 0;
        $minutes = 0;
        $seconds = 0;

        if( preg_match('/^\d+:\d+$/', $str) ) {
            list(, $minutes, $seconds) = explode(':', $str);
        }
        elseif( preg_match('/^\d+:\d+:\d+$/', $str) ) {
            list($hours, $minutes, $seconds) = explode(':', $str);
        }
        else {

            // convert invalid characters to spaces
            $str = preg_replace('/[^a-z0-9. ]+/iu', ' ', $str);

            // strip multiple spaces
            $str = preg_replace('/ {2,}/u', ' ', $str);

            // compress scales and units together so '2 hours' => '2hours'
            $str = preg_replace('/([0-9.]+) ([cdehimnorstu]+)/u', '$1$2', $str);

            foreach( explode(' ', $str) as $item ) {

                if( !preg_match('/^([0-9.]+)([cdehimnorstu]+)$/u', $item, $m) ) {
                    return 0;
                }

                list(, $scale, $unit) = $m;

                $scale = ((float) $scale != (int) $scale) ? (float) $scale : (int) $scale;

                if( preg_match('/^h(r|our|ours)?$/u', $unit) && !$hours ) {
                    $hours = $scale;
                }
                elseif( preg_match('/^m(in|ins|inute|inutes)?$/u', $unit) && !$minutes ) {
                    $minutes = $scale;
                }
                elseif( preg_match('/^s(ec|ecs|econd|econds)?$/u', $unit) && !$seconds ) {
                    $seconds = $scale;
                }
                else {
                    return 0;
                }

            }

        }

        return intval(($hours * 3600) + ($minutes * 60) + $seconds);

    }

}
