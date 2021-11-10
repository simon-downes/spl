<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\helpers;

use Closure;
use RuntimeException;

class HttpHelper {

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Make an HTTP request and return a simple object representing the response.
     *
     * @param string $method
     * @param string $url
     * @param string $body
     * @param array $headers
     * @return array
     */
    public static function http( string $url, array $headers = [], string $method = 'GET', string|array $body = '' ): object {

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_HEADER, 1);            // return the response headers
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);    // return the response body

        // set the correct request method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        // if we have headers than add them
        if( !empty($headers) ) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // if there's a body then add that
        if( !empty($body) ) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $headers);
        }

        // send the request
        $response = curl_exec($ch);

        if( $error = curl_error($ch) ) {
            throw new RuntimeException("Curl Error: {$error}");
        }

        curl_close($ch);

        // split the response into header and body strings
        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        // parse the headers into an associative array
        $headers = static::http_parse_headers($headers);

        // decode json response bodies to an array
        if( str_starts_with($headers['content-type'] ?? '', 'application/json') ) {
            $body = json_decode($body, true);
        }

        $status_line = $headers['http'];
        unset($headers['http']);

        return (object) [
            'http_version'   => $status_line['http_version'],
            'status_code'    => $status_line['status_code'],
            'status_message' => $status_line['status_message'],
            'headers'        => $headers,
            'body'           => $body
        ];

    }

    /**
     * Parse a string or list of HTTP headers into an array.
     * Header names are normalised to lowercase, duplicate values are concatenated with a comma,
     * the `set-cookie` header is returned as an array (if present)
     */
    public static function http_parse_headers( array|string $headers ): array {

        if( is_string($headers) ) {
            $headers = explode("\r\n", $headers);
        }

        $parsed = [];

        foreach( $headers as $i => $line ) {

            if( $i == 0 && !strpos($line, ':') ) {
                $parsed['http'] = static::http_parse_http_status($line);
                continue;
            }

            list($key, $value) = explode(":", $line, 2);

            $key = strtolower($key);
            $value = trim($value);

            // combine multiple headers with the same name
            // https://stackoverflow.com/questions/3241326/set-more-than-one-http-header-with-the-same-name
            if( $key == 'set-cookie' ) {
                $parsed[$key][] = $value;
            }
            elseif( isset($parsed[$key]) ) {
                $parsed[$key] .= ','. $value;
            }
            else {
                $parsed[$key] = $value;
            }

        }

        return $parsed;

    }

    /**
     * Parse the first line of an HTTP response and return an array containing:
     * - http_version
     * - status_code
     * - status_message
     */
    public static function http_parse_http_status( string $status_line ): array {

        // Status-Line = HTTP-Version SP Status-Code SP Reason-Phrase CRLF
        $parts = explode(" ", $status_line, 3);

        if( count($parts) != 3 ) {
            throw new RuntimeException("Invalid status line: {$status_line}");
        }

        return [
            'http_version'   => str_replace('HTTP/', '', $parts[0]),
            'status_code'    => (int) $parts[1],
            'status_message' => $parts[2],
        ];

    }

}
