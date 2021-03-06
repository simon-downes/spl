<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\helpers;

use InvalidArgumentException;

class StringHelper {

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Parse a URL string into an array of components.
     * Similar to the native parse_url except that the returned array will contain all components
     * and the query component is replaced with an options component containing a decoded array.
     *
     * @param  string       $url        either a string array or a partial list of url components
     * @param  array        $defaults   an array of default values for components
     * @return array|null   Returns false if the URL could not be parsed
     */
    public static function parseURL( string $url, array $defaults = [] ) : array|null {

        $parts = parse_url(urldecode($url));

        if( $parts === false ) {
            return null;
        }

        $url = [];

        foreach( ['scheme', 'host', 'port', 'user', 'pass', 'path'] as $k ) {
            $url[$k] = $parts[$k] ?? $defaults[$k] ?? '';
        }

        $url['options'] = [];

        if( isset($parts['query']) ) {
            parse_str($parts['query'], $url['options']);
        }

        return $url;

    }

    public static function buildURL( array $parts, bool $show_pw = false ): string {

        $url = '';

        if( isset($parts['scheme']) ) {
            $url .= $parts['scheme']. '://';
        }

        if( isset($parts['user']) ) {
            $url .= $parts['user'];
            if( isset($parts['pass']) ) {
                $url .= ':'. ($show_pw ? $parts['pass'] : '<password>');
            }
            $url .= '@';
        }

        if( isset($parts['host']) ) {
            $url .= $parts['host'];
            if( isset($parts['port']) ) {
                $url .= ':'. $parts['port'];
            }
        }

        $url .= $parts['path'] ?? '/';

        return $url;

    }

    /**
     * Returns a string of cryptographically strong random hex digits.
     */
    public static function randomHex( int $length = 40 ): string {

        if( ($length % 2) == 1 ) {
            throw new InvalidArgumentException("Hex strings must be an even number in length");
        }

        $hex = bin2hex(random_bytes((int) ceil($length / 2)));

        return $hex;

    }

    /**
     * Returns a string of the specified length containing only the characters in the $allowed parameter.
     * This function is not cryptographically strong.
     *
     * @param  int  $length    length of the desired string
     * @param  string  $allowed   the characters allowed to appear in the output
     */
    public static function randomString( int $length, string $allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ' ): string {
        $out = '';
        $max = strlen($allowed) - 1;
        for( $i = 0; $i < $length; $i++ ) {
            $out .= $allowed[mt_rand(0, $max)];
        }
        return $out;
    }

    /**
     * Convert a camel-cased string to lower case with underscores
     */
    public static function uncamelise( string $str ): string {
        return mb_strtolower(
            preg_replace(
                '/^A-Z^a-z^0-9]+/', '_',
                preg_replace('/([a-z\d])([A-Z])/u', '$1_$2',
                    preg_replace('/([A-Z+])([A-Z][a-z])/u', '$1_$2', $str)
                )
            )
        );
    }

    /**
     * Convert a string into a format safe for use in urls.
     * Converts any accent characters to their equivalent normal characters
     * and then any sequence of two or more non-alphanumeric characters to a dash.
     */
    public static function slugify( string $str ): string {
        $chars = ['&' => '-and-', '???' => '-EUR-', '??' => '-GBP-', '$' => '-USD-'];
        return trim(preg_replace('/([^a-z0-9]+)/u', '-', mb_strtolower(strtr(static::removeAccents($str), $chars))), '-');
    }

    /**
     * Converts accent characters to their ASCII counterparts.
     */
    public static function removeAccents( string $str ): string {
        $chars = [
            '??' => 'a', '??' => 'o', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A',
            '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'a',
            '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a',
            '??' => 'a', '??' => 'a', '??' => 'C', '??' => 'C', '??' => 'C', '??' => 'C',
            '??' => 'C', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c',
            '??' => 'D', '??' => 'D', '??' => 'd', '??' => 'd', '??' => 'E', '??' => 'E',
            '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E',
            '??' => 'E', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e',
            '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'f', '??' => 'G',
            '??' => 'G', '??' => 'G', '??' => 'G', '??' => 'g', '??' => 'g', '??' => 'g',
            '??' => 'g', '??' => 'H', '??' => 'H', '??' => 'h', '??' => 'h', '??' => 'I',
            '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I',
            '??' => 'I', '??' => 'I', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i',
            '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'J',
            '??' => 'j', '??' => 'K', '??' => 'k', '??' => 'k', '??' => 'L', '??' => 'L',
            '??' => 'L', '??' => 'L', '??' => 'L', '??' => 'l', '??' => 'l', '??' => 'l',
            '??' => 'l', '??' => 'l', '??' => 'N', '??' => 'N', '??' => 'N', '??' => 'N',
            '??' => 'N', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n',
            '??' => 'n', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O',
            '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'o', '??' => 'o',
            '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o',
            '??' => 'o', '??' => 'o', '??' => 'R', '??' => 'R', '??' => 'R', '??' => 'r',
            '??' => 'r', '??' => 'r', '??' => 'S', '??' => 'S', '??' => 'S', '??' => 'S',
            '??' => 'S', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's',
            '??' => 's', '??' => 'T', '??' => 'T', '??' => 'T', '??' => 'T', '??' => 't',
            '??' => 't', '??' => 't', '??' => 't', '??' => 'U', '??' => 'U', '??' => 'U',
            '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'U',
            '??' => 'U', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u',
            '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'W',
            '??' => 'w', '??' => 'Y', '??' => 'Y', '??' => 'Y', '??' => 'y', '??' => 'y',
            '??' => 'y', '??' => 'Z', '??' => 'Z', '??' => 'Z', '??' => 'z', '??' => 'z',
            '??' => 'z', '??' => 'AE', '??' => 'ae', '??' => 'IJ', '??' => 'ij',
            '??' => 'OE', '??' => 'oe', '??' => 'ss', '??' => 'th', '??' => 'th',
        ];
        return strtr($str, $chars);
    }

    /**
     * Converts a UTF-8 string to Latin-1 with unsupported characters encoded as numeric entities.
     * Example: I want to turn text like
     * hello ?? ?? ???
     * into
     * hello ?? &#946; &#27700;
     */
    public static function latin1( string $str ): string {
        return utf8_decode(
            mb_encode_numericentity(
                $str,
                [0x0100, 0xFFFF, 0, 0xFFFF],
                'UTF-8'
            )
        );
    }

    /**
     * Converts a Latin-1 string to UTF-8 and decodes entities.
     */
    public static function utf8( string $str ): string {
        return html_entity_decode(
            mb_convert_encoding(
                $str,
                'UTF-8',
                'ISO-8859-1'
            ),
            ENT_NOQUOTES,
            'UTF-8'
        );
    }

    /**
     * Return the ordinal suffix (st, nd, rd, th) of a number.
     * Taken from: http://stackoverflow.com/questions/3109978/php-display-number-with-ordinal-suffix
     */
    public static function ordinal( int $n ): string {

        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];

        // if tens digit is 1, 2 or 3 then use th instead of usual ordinal
        if( ($n % 100) >= 11 && ($n % 100) <= 13 ) {
            return "{$n}th";
        }

        return "{$n}{$ends[$n % 10]}";

    }

    /**
     * Convert a number of bytes to a human-friendly string using the largest suitable unit.
     * Taken from: http://www.php.net/manual/de/function.filesize.php#91477
     */
    public static function sizeFormat( int $bytes, int $precision ): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision). ' '. $units[$pow];
    }

    /**
     * Remove XSS vulnerabilities from a string.
     * Shamelessly ripped from Kohana v2 and then tweaked to remove control characters
     * and replace the associated regex components with \s instead.
     * Also added a couple of other tags to the really bad list.
     * Handles most of the XSS vectors listed at http://ha.ckers.org/xss.html
     */
    public static function xssClean( string $str, string $charset = 'UTF-8' ): string {

        if( empty($str) ) {
            return $str;
        }

        // strip any raw control characters that might interfere with our cleaning
        $str = static::stripControlChars($str);

        // fix and decode entities (handles missing ; terminator)
        $str = str_replace(['&amp;','&lt;','&gt;'], ['&amp;amp;','&amp;lt;','&amp;gt;'], $str);
        $str = preg_replace('/(&#*\w+)\s+;/u', '$1;', $str);
        $str = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $str);
        $str = html_entity_decode($str, ENT_COMPAT, $charset);

        // strip any control characters that were sneakily encoded as entities
        $str = static::stripControlChars($str);

        // normalise line endings
        $str = static::normaliseLineEndings($str);

        // remove any attribute starting with "on" or xmlns
        $str = preg_replace('#(?:on[a-z]+|xmlns)\s*=\s*[\'"\s]?[^\'>"]*[\'"\s]?\s?#iu', '', $str);

        // remove javascript: and vbscript: protocols and -moz-binding CSS property
        $str = preg_replace('#([a-z]*)\s*=\s*([`\'"]*)\s*j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:#iu', '$1=$2nojavascript...', $str);
        $str = preg_replace('#([a-z]*)\s*=([\'"]*)\s*v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:#iu', '$1=$2novbscript...', $str);
        $str = preg_replace('#([a-z]*)\s*=([\'"]*)\s*-moz-binding\s*:#u', '$1=$2nomozbinding...', $str);

        // only works in IE: <span style="width: expression(alert('XSS!'));"></span>
        $str = preg_replace('#(<[^>]+?)style\s*=\s*[`\'"]*.*?expression\s*\([^>]*+>#isu', '$1>', $str);
        $str = preg_replace('#(<[^>]+?)style\s*=\s*[`\'"]*.*?behaviour\s*\([^>]*+>#isu', '$1>', $str);
        $str = preg_replace('#(<[^>]+?)style\s*=\s*[`\'"]*.*?s\s*c\s*r\s*i\s*p\s*t\s*:*[^>]*+>#isu', '$1>', $str);

        // remove namespaced elements (we do not need them)
        $str = preg_replace('#</*\w+:\w[^>]*+>#iu', '', $str);

        // remove data URIs
        $str = preg_replace("#data:[\w/]+;\w+,[\w\r\n+=/]*#iu", "data: not allowed", $str);

        // remove really unwanted tags
        do {
            $old = $str;
            $str = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|body|embed|frame(?:set)?|head|html|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#iu', '', $str);
        }
        while( $old !== $str );

        return $str;

    }

    /**
     * Remove every control character except newline (10/x0A) carriage return (13/x0D), and horizontal tab (09/x09)
     */
    public static function stripControlChars( string $str ): string {

        do {
            // 00-08, 11, 12, 14-31, 127
            $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/Su', '', $str, -1, $count);
        }
        while( $count );

        return $str;

    }

    /**
     * Ensures that a string has consistent line-endings.
     * All line-ending are converted to LF with maximum of two consecutive.
     */
    public static function normaliseLineEndings( string $str ): string {
        $str = str_replace("\r\n", "\n", $str);
        $str = str_replace("\r", "\n", $str);
        return preg_replace("/\n{2,}/", "\n\n", $str);
    }

}
