<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use BadMethodCallException;
use RuntimeException;

/**
 * HTTP client utility class.
 * 
 * Provides a simple interface for making HTTP requests.
 */
class Http {

    /**
     * HTTP method constants.
     */
    public const GET     = 'GET';
    public const POST    = 'POST';
    public const PUT     = 'PUT';
    public const DELETE  = 'DELETE';
    public const OPTIONS = 'OPTIONS';

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Forward static method calls to the request() method.
     * 
     * Allows for convenient syntax like Http::get(), Http::post(), etc.
     *
     * @param string $method    The HTTP method to use (get, post, etc.)
     * @param array  $arguments The arguments to pass to the request method
     * 
     * @return object The HTTP response object
     * 
     * @throws BadMethodCallException If the method is not a valid HTTP method
     */
    public static function __callStatic(string $method, array $arguments): object {

        $method = strtoupper($method);

        switch ($method) {
            case static::GET:
            case static::POST:
            case static::PUT:
            case static::DELETE:
            case static::OPTIONS:
                return static::request($method, ...$arguments);

            default:
                throw new BadMethodCallException(sprintf("Unknown method %s::%s", __CLASS__, $method));
        }

    }

    /**
     * Make an HTTP request and return a simple object representing the response.
     *
     * @param string       $method  The HTTP method to use (GET, POST, etc.)
     * @param string       $url     The URL to request
     * @param array        $headers Optional request headers
     * @param string|array $body    Optional request body
     * 
     * @return object The HTTP response object with properties:
     *                - http_version: string
     *                - status_code: int
     *                - status_message: string
     *                - headers: array
     *                - body: string|array (JSON responses are automatically decoded)
     * 
     * @throws RuntimeException If the cURL request fails
     */
    public static function request(string $method, string $url, array $headers = [], string|array $body = ''): object {

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_HEADER, true);            // return the response headers
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);    // return the response body

        // set the correct request method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        // if we have headers than add them
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // if there's a body then add that
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $headers);
        }

        // send the request
        $response = (string) curl_exec($ch);

        if ($error = curl_error($ch)) {
            throw new RuntimeException("Curl Error: {$error}");
        }

        curl_close($ch);

        // split the response into header and body strings
        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        // parse the headers into an associative array
        $headers = static::parse_headers($headers);

        // decode json response bodies to an array
        if (str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $body = json_decode($body, true);
        }

        $status_line = $headers['http'];
        unset($headers['http']);

        return (object) [
            'http_version'   => $status_line['http_version'],
            'status_code'    => $status_line['status_code'],
            'status_message' => $status_line['status_message'],
            'headers'        => $headers,
            'body'           => $body,
        ];

    }

    /**
     * Parse a string or list of HTTP headers into an array.
     * 
     * Header names are normalised to lowercase, duplicate values are concatenated with a comma,
     * the `set-cookie` header is returned as an array (if present).
     *
     * @param array|string $headers The headers to parse
     * 
     * @return array The parsed headers
     */
    public static function parse_headers(array|string $headers): array {

        if (is_string($headers)) {
            $headers = explode("\r\n", $headers);
        }

        $parsed = [];

        foreach ($headers as $i => $line) {

            if ($i == 0 && !strpos($line, ':')) {
                $parsed['http'] = static::parse_status_line($line);
                continue;
            }

            list($key, $value) = explode(":", $line, 2);

            $key = strtolower($key);
            $value = trim($value);

            // combine multiple headers with the same name
            // https://stackoverflow.com/questions/3241326/set-more-than-one-http-header-with-the-same-name
            if ($key == 'set-cookie') {
                $parsed[$key][] = $value;
            }
            elseif (isset($parsed[$key])) {
                $parsed[$key] .= ',' . $value;
            }
            else {
                $parsed[$key] = $value;
            }

        }

        return $parsed;

    }

    /**
     * Parse the first line of an HTTP response.
     *
     * @param string $status_line The status line to parse
     * 
     * @return array An array containing:
     *               - http_version: string
     *               - status_code: int
     *               - status_message: string
     * 
     * @throws RuntimeException If the status line is invalid
     */
    public static function parse_status_line(string $status_line): array {

        // Status-Line = HTTP-Version SP Status-Code SP Reason-Phrase CRLF
        $parts = explode(" ", trim($status_line), 3);

        if (count($parts) != 3) {
            throw new RuntimeException("Invalid status line: {$status_line}");
        }

        return [
            'http_version'   => str_replace('HTTP/', '', $parts[0]),
            'status_code'    => (int) $parts[1],
            'status_message' => $parts[2],
        ];

    }

}
