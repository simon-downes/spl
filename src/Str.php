<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use DateTimeInterface;

class Str {

    protected static $plural = [
        '/(quiz)$/i'               => "$1zes",
        '/^(ox)$/i'                => "$1en",
        '/([m|l])ouse$/i'          => "$1ice",
        '/(matr|vert|ind)ix|ex$/i' => "$1ices",
        '/(x|ch|ss|sh)$/i'         => "$1es",
        '/([^aeiouy]|qu)y$/i'      => "$1ies",
        '/(hive)$/i'               => "$1s",
        '/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
        '/(shea|lea|loa|thie)f$/i' => "$1ves",
        '/sis$/i'                  => "ses",
        '/([ti])um$/i'             => "$1a",
        '/(tomat|potat|ech|her|vet)o$/i' => "$1oes",
        '/(bu)s$/i'                => "$1ses",
        '/(alias)$/i'              => "$1es",
        '/(ax|test)is$/i'          => "$1es",
        '/(us)$/i'                 => "$1es",
        '/s$/i'                    => "s",
        '/$/'                      => "s",
    ];

    protected static $singular = [
        '/(quiz)zes$/i'             => "$1",
        '/(matr)ices$/i'            => "$1ix",
        '/(vert|ind)ices$/i'        => "$1ex",
        '/^(ox)en$/i'               => "$1",
        '/(alias)es$/i'             => "$1",
        '/(cris|ax|test)es$/i'      => "$1is",
        '/(shoe)s$/i'               => "$1",
        '/(o)es$/i'                 => "$1",
        '/(bus)es$/i'               => "$1",
        '/([m|l])ice$/i'            => "$1ouse",
        '/(x|ch|ss|sh)es$/i'        => "$1",
        '/(m)ovies$/i'              => "$1ovie",
        '/(s)eries$/i'              => "$1eries",
        '/([^aeiouy]|qu)ies$/i'     => "$1y",
        '/([lr])ves$/i'             => "$1f",
        '/(tive)s$/i'               => "$1",
        '/(hive)s$/i'               => "$1",
        '/(li|wi|kni)ves$/i'        => "$1fe",
        '/(shea|loa|lea|thie)ves$/i' => "$1f",
        '/(^analy)ses$/i'           => "$1sis",
        '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i'  => "$1$2sis",
        '/([ti])a$/i'               => "$1um",
        '/(n)ews$/i'                => "$1ews",
        '/(h|bl)ouses$/i'           => "$1ouse",
        '/(corpse)s$/i'             => "$1",
        '/(us)es$/i'                => "$1",
        '/s$/i'                     => "",
    ];

    protected static $irregular = [
        'move'   => 'moves',
        'foot'   => 'feet',
        'goose'  => 'geese',
        'sex'    => 'sexes',
        'child'  => 'children',
        'man'    => 'men',
        'tooth'  => 'teeth',
        'person' => 'people',
    ];

    protected static $uncountable = [
        'sheep',
        'fish',
        'deer',
        'series',
        'species',
        'money',
        'rice',
        'information',
        'equipment',
    ];

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Parse a URL string into an array of components.
     * Similar to the native parse_url except that the returned array will contain all components
     * and the query component is replaced a decoded array.
     *
     * @param  string       $url        either a string array or a partial list of url components
     * @param  array        $defaults   an array of default values for components
     * @return array|null   Returns false if the URL could not be parsed
     */
    public static function parseURL(string $url, array $defaults = []): array|null {

        $parts = parse_url(urldecode($url));

        if ($parts === false) {
            return null;
        }

        $url = [];

        foreach (['scheme', 'host', 'port', 'user', 'pass', 'path'] as $k) {
            $url[$k] = $parts[$k] ?? $defaults[$k] ?? '';
        }

        $url['query'] = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $url['query']);
        }

        return $url;

    }

    public static function buildURL(array $parts, bool $show_pw = false): string {

        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':' . ($show_pw ? $parts['pass'] : '<password>');
            }
            $url .= '@';
        }

        if (isset($parts['host'])) {
            $url .= $parts['host'];
            if (isset($parts['port'])) {
                $url .= ':' . $parts['port'];
            }
        }

        $url .= $parts['path'] ?? '/';

        return $url;

    }

    /**
     * Convert a camel-cased string to lower case with underscores
     */
    public static function uncamelise(string $str): string {
        return mb_strtolower(
            preg_replace(
                '/^A-Z^a-z^0-9]+/',
                '_',
                preg_replace(
                    '/([a-z\d])([A-Z])/u',
                    '$1_$2',
                    preg_replace('/([A-Z+])([A-Z][a-z])/u', '$1_$2', $str),
                ),
            ),
        );
    }

    /**
     * Convert a string into a format safe for use in urls.
     * Converts any accent characters to their equivalent normal characters
     * and then any sequence of two or more non-alphanumeric characters to a dash.
     */
    public static function slugify(string $str): string {
        $chars = ['&' => '-and-', '€' => '-EUR-', '£' => '-GBP-', '$' => '-USD-'];
        return trim(preg_replace('/([^a-z0-9]+)/u', '-', mb_strtolower(strtr(static::removeAccents($str), $chars))), '-');
    }

    /**
     * Converts accent characters to their ASCII counterparts.
     */
    public static function removeAccents(string $str): string {
        $chars = [
            'ª' => 'a', 'º' => 'o', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A', 'à' => 'a',
            'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
            'ă' => 'a', 'ą' => 'a', 'Ç' => 'C', 'Ć' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C',
            'Č' => 'C', 'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
            'Đ' => 'D', 'Ď' => 'D', 'đ' => 'd', 'ď' => 'd', 'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ę' => 'E',
            'Ě' => 'E', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
            'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ƒ' => 'f', 'Ĝ' => 'G',
            'Ğ' => 'G', 'Ġ' => 'G', 'Ģ' => 'G', 'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g',
            'ģ' => 'g', 'Ĥ' => 'H', 'Ħ' => 'H', 'ĥ' => 'h', 'ħ' => 'h', 'Ì' => 'I',
            'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ĩ' => 'I', 'Ī' => 'I', 'Ĭ' => 'I',
            'Į' => 'I', 'İ' => 'I', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'Ĵ' => 'J',
            'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k', 'ĸ' => 'k', 'Ĺ' => 'L', 'Ļ' => 'L',
            'Ľ' => 'L', 'Ŀ' => 'L', 'Ł' => 'L', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
            'ŀ' => 'l', 'ł' => 'l', 'Ñ' => 'N', 'Ń' => 'N', 'Ņ' => 'N', 'Ň' => 'N',
            'Ŋ' => 'N', 'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
            'ŋ' => 'n', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ø' => 'O', 'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O', 'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o',
            'ő' => 'o', 'ð' => 'o', 'Ŕ' => 'R', 'Ŗ' => 'R', 'Ř' => 'R', 'ŕ' => 'r',
            'ŗ' => 'r', 'ř' => 'r', 'Ś' => 'S', 'Ŝ' => 'S', 'Ş' => 'S', 'Š' => 'S',
            'Ș' => 'S', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
            'ſ' => 's', 'Ţ' => 'T', 'Ť' => 'T', 'Ŧ' => 'T', 'Ț' => 'T', 'ţ' => 't',
            'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U',
            'Ü' => 'U', 'Ũ' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U',
            'Ų' => 'U', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u',
            'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'Ŵ' => 'W',
            'ŵ' => 'w', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ŷ' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
            'ŷ' => 'y', 'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z', 'ź' => 'z', 'ż' => 'z',
            'ž' => 'z', 'Æ' => 'AE', 'æ' => 'ae', 'Ĳ' => 'IJ', 'ĳ' => 'ij',
            'Œ' => 'OE', 'œ' => 'oe', 'ß' => 'ss', 'þ' => 'th', 'Þ' => 'th',
        ];
        return strtr($str, $chars);
    }

    /**
     * Return the ordinal suffix (st, nd, rd, th) of a number.
     * Taken from: http://stackoverflow.com/questions/3109978/php-display-number-with-ordinal-suffix
     */
    public static function ordinal(int $n): string {

        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];

        // if tens digit is 1, 2 or 3 then use th instead of usual ordinal
        if (($n % 100) >= 11 && ($n % 100) <= 13) {
            return "{$n}th";
        }

        return "{$n}{$ends[$n % 10]}";

    }

    /**
     * Convert a number of bytes to a human-friendly string using the largest suitable unit.
     * Taken from: http://www.php.net/manual/de/function.filesize.php#91477
     */
    public static function sizeFormat(int $bytes, int $precision): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Remove XSS vulnerabilities from a string.
     * Shamelessly ripped from Kohana v2 and then tweaked to remove control characters
     * and replace the associated regex components with \s instead.
     * Also added a couple of other tags to the really bad list.
     * Handles most of the XSS vectors listed at http://ha.ckers.org/xss.html
     */
    public static function xssClean(string $str, string $charset = 'UTF-8'): string {

        if (empty($str)) {
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
        while ($old !== $str);

        return $str;

    }

    /**
     * Remove every control character except newline (10/x0A) carriage return (13/x0D), and horizontal tab (09/x09)
     */
    public static function stripControlChars(string $str): string {

        do {
            // 00-08, 11, 12, 14-31, 127
            $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/Su', '', $str, -1, $count);
        }
        while ($count);

        return $str;

    }

    /**
     * Ensures that a string has consistent line-endings.
     * All line-ending are converted to LF with maximum of two consecutive.
     */
    public static function normaliseLineEndings(string $str): string {
        $str = str_replace("\r\n", "\n", $str);
        $str = str_replace("\r", "\n", $str);
        return preg_replace("/\n{2,}/", "\n\n", $str);
    }

    public static function pluralise(string $str): string {

        // save some time in the case that singular and plural are the same
        if (in_array(mb_strtolower($str), self::$uncountable)) {
            return $str;
        }

        // check for irregular singular forms
        foreach (self::$irregular as $pattern => $result) {
            $pattern = '/' . $pattern . '$/iu';
            if (preg_match($pattern, $str)) {
                return preg_replace($pattern, $result, $str);
            }
        }

        // check for matches using regular expressions
        foreach (self::$plural as $pattern => $result) {
            if (preg_match($pattern, $str)) {
                return preg_replace($pattern, $result, $str);
            }
        }

        return $str;

    }

    public static function singularise(string $str): string {

        // save some time in the case that singular and plural are the same
        if (in_array(mb_strtolower($str), self::$uncountable)) {
            return $str;
        }

        // check for irregular plural forms
        foreach (self::$irregular as $result => $pattern) {
            $pattern = '/' . $pattern . '$/iu';
            if (preg_match($pattern, $str)) {
                return preg_replace($pattern, $result, $str);
            }
        }

        // check for matches using regular expressions
        foreach (self::$singular as $pattern => $result) {
            if (preg_match($pattern, $str)) {
                return preg_replace($pattern, $result, $str);
            }
        }

        return $str;

    }

    /**
     * Converts a string representation containing one or more of hours, minutes and seconds into a total number of seconds.
     * e.g. seconds("3 hours 4 minutes 10 seconds"), seconds("5min"), seconds("4.5h")
     */
    public static function seconds(string $str): int {

        $hours   = 0;
        $minutes = 0;
        $seconds = 0;

        if (preg_match('/^\d+:\d+$/', $str)) {
            list(, $minutes, $seconds) = explode(':', $str);
        }
        elseif (preg_match('/^\d+:\d+:\d+$/', $str)) {
            list($hours, $minutes, $seconds) = explode(':', $str);
        }
        else {

            // convert invalid characters to spaces
            $str = preg_replace('/[^a-z0-9. ]+/iu', ' ', $str);

            // strip multiple spaces
            $str = preg_replace('/ {2,}/u', ' ', $str);

            // compress scales and units together so '2 hours' => '2hours'
            $str = preg_replace('/([0-9.]+) ([cdehimnorstu]+)/u', '$1$2', $str);

            foreach (explode(' ', $str) as $item) {

                if (!preg_match('/^([0-9.]+)([cdehimnorstu]+)$/u', $item, $m)) {
                    return 0;
                }

                list(, $scale, $unit) = $m;

                $scale = ((float) $scale != (int) $scale) ? (float) $scale : (int) $scale;

                if (preg_match('/^h(r|our|ours)?$/u', $unit) && !$hours) {
                    $hours = $scale;
                }
                elseif (preg_match('/^m(in|ins|inute|inutes)?$/u', $unit) && !$minutes) {
                    $minutes = $scale;
                }
                elseif (preg_match('/^s(ec|ecs|econd|econds)?$/u', $unit) && !$seconds) {
                    $seconds = $scale;
                }
                else {
                    return 0;
                }

            }

        }

        return intval(($hours * 3600) + ($minutes * 60) + $seconds);

    }

    public static function date(int|string|DateTimeInterface $time = ''): string {
        return date('Y-m-d', SPL::makeTimestamp($time));
    }

    public static function datetime(int|string|DateTimeInterface $time = ''): string {
        return date('Y-m-d H:i:s', SPL::makeTimestamp($time));
    }

}
