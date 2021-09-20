<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\helpers;

use Closure;

class ArrayHelper {

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Convert an array of variables into an array of unique integer values.
     */
    public static function uniqueIntegers( array $var ): array {
        // array_values() is needed as array_unique() will preserve keys
        return array_values(
            array_unique(
                array_map('intval', $var)
            )
        );
    }

    /**
     * Determine if an array is an associative array.
     * An array is considered associative if any of its keys are strings.
     * Taken from: http://stackoverflow.com/questions/173400/php-arrays-a-good-way-to-check-if-an-array-is-associative-or-numeric/4254008#4254008
     */
    public static function isAssoc( array $var ): bool {
        return (bool) count(
            array_filter(
                array_keys($var),
                'is_string'
            )
        );
    }

    /**
     * Filter an array and return all entries that are instances of the specified class.
     *
     * @param  array   $var     An array to filter
     * @param  string  $class   The class that items should be an instance of
     */
    public static function filterObjects( array $var, string $class ): array {
        return array_filter(
            $var,
            function( $item ) use ($class) {
                return ($item instanceof $class);
            }
        );
    }

    /**
     * Return value of the specified key from an array or object or a default if the key isn't set.
     */
    public static function get( array|object $var, int|string $key, mixed $default = null ): mixed {

        if( isset($var[$key]) ) {
            return $var[$key];
        }
        elseif( isset($var->$key) ) {
            return $var->$key;
        }

        return $default;

    }

    /**
     * Extract the items that are null from an array; keys are preserved.
     */
    public static function getNullItems( array $var ): array {
        return array_filter(
            $var,
            function( $a ) {
                return $a === null;
            }
        );
    }

    /**
     * Extract a single field from an array of arrays or objects.
     */
    public static function pluck( array $items, string $field, bool $preserve_keys = true ): array {
        $values = [];
        foreach( $items as $k => $v ) {
            $values[$k] = static::get($v, $field);
        }
        return $preserve_keys ? $values : array_values($values);
    }

    /**
     * Sum a single field from an array of arrays or objects.
     */
    public static function sum( array $items, string $field ) {
        return array_sum(static::pluck($items, $field));
    }

    /**
     * Return the minimum value of a single field from an array of arrays or objects.
     */
    public static function min( array $items, string $field ) {
        return min(static::pluck($items, $field));
    }

    /**
     * Return the maximum value of a single field from an array of arrays or objects.
     */
    public static function max( array $items, string $field ) {
        return max(static::pluck($items, $field));
    }

    /**
     * Implode an associative array into a string of key/value pairs.
     *
     * @param  array   $var          The array to implode
     * @param  string  $glue_outer   A string used to delimit items
     * @param  string  $glue_inner   A string used to separate keys and values
     * @param  boolean $skip_empty   Should empty values be included?
     * @return string
     */
    public static function implodeAssoc( $var, string $glue_outer = ',', string $glue_inner = '=', bool $skip_empty = true ): string {
        $output = [];
        foreach( $var as $k => $v ) {
            if( $skip_empty && empty($v) ) {
                continue;
            }
            $output[] = "{$k}{$glue_inner}{$v}";
        }
        return implode($glue_outer, $output);
    }

    /**
     * Create a comparison function for sorting multi-dimensional arrays.
     * http://stackoverflow.com/questions/96759/how-do-i-sort-a-multidimensional-array-in-php/16788610#16788610
     *
     * Each parameter to this function is a criteria and can either be a string
     * representing a column to sort or a numerically indexed array containing:
     * 0 => the column name to sort on (mandatory)
     * 1 => either SORT_ASC or SORT_DESC (optional)
     * 2 => a projection function (optional)
     *
     * The return value is a function that can be passed to usort() or uasort().
     *
     * @return \Closure
     */
    public static function makeComparer( ...$criteria ): Closure {

        // normalize criteria up front so that the comparer finds everything tidy
        foreach( $criteria as $index => $criterion ) {
            $criteria[$index] = is_array($criterion)
                ? array_pad($criterion, 3, null)
                : array($criterion, SORT_ASC, null);
        }

        return function( $first, $second ) use ($criteria) {
            foreach( $criteria as $criterion ) {

                // how will we compare this round?
                list($column, $sort_order, $projection) = $criterion;
                $sort_order = $sort_order === SORT_DESC ? -1 : 1;

                // if a projection was defined project the values now
                if( $projection ) {
                    $lhs = call_user_func($projection, $first[$column]);
                    $rhs = call_user_func($projection, $second[$column]);
                }
                else {
                    $lhs = $first[$column];
                    $rhs = $second[$column];
                }

                // do the actual comparison; do not return if equal, move on to the next column
                if( $lhs < $rhs ) {
                    return -1 * $sort_order;
                }
                elseif( $lhs > $rhs ) {
                    return 1 * $sort_order;
                }

            }
            return 0; // all sortable columns contain the same values, so $first == $second
        };

    }

}
