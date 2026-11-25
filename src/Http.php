<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Simple HTTP client for making requests.
 *
 * Provides a convenient interface for making HTTP requests with cURL. Responses are
 * returned as HttpResponse objects holding the final response's status, headers and
 * raw body.
 *
 * Every request has a connect and a total timeout (see DEFAULTS), which can be changed
 * per request through the `$options` argument. Redirects are only followed when
 * `follow_redirects` is true.
 *
 * Usage:
 *   $response = Http::get('https://api.example.com/users');
 *   $users    = $response->json();
 *   $response = Http::post('https://api.example.com/users', ['Content-Type: text/plain'], 'John');
 *   $response = Http::get('https://example.com/slow', options: ['timeout' => 60, 'follow_redirects' => true]);
 *   $response = Http::post('https://api.example.com/users', options: ['json' => ['name' => 'John']]);
 */
class Http {

    /**
     * HTTP method constants.
     */
    public const GET     = 'GET';
    public const HEAD    = 'HEAD';
    public const POST    = 'POST';
    public const PUT     = 'PUT';
    public const DELETE  = 'DELETE';
    public const OPTIONS = 'OPTIONS';

    /**
     * Default request options, overridable per request via `$options`:
     * - connect_timeout: seconds allowed to establish the connection (int >= 0, 0 = no limit)
     * - timeout: seconds allowed for the whole request (int >= 0, 0 = no limit)
     * - follow_redirects: follow `Location` responses, up to MAX_REDIRECTS hops (bool)
     *
     * request() also accepts a `json` option, which has no default; see request().
     */
    public const DEFAULTS = [
        'connect_timeout'  => 10,
        'timeout'          => 30,
        'follow_redirects' => false,
    ];

    /**
     * Maximum number of redirects followed when `follow_redirects` is enabled.
     */
    public const MAX_REDIRECTS = 5;

    /**
     * Helpers cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Makes a GET request.
     *
     * @param list<string> $headers Request headers, e.g. ['Accept: application/json']
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function get(string $url, array $headers = [], array $options = []): HttpResponse {
        return static::request(static::GET, $url, $headers, '', $options);
    }

    /**
     * Makes a HEAD request; the response body is always empty.
     *
     * @param list<string> $headers Request headers
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function head(string $url, array $headers = [], array $options = []): HttpResponse {
        return static::request(static::HEAD, $url, $headers, '', $options);
    }

    /**
     * Makes a POST request.
     *
     * @param list<string> $headers Request headers
     * @param string|array<string, mixed> $body Raw body string, or an array sent as multipart/form-data
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function post(string $url, array $headers = [], string|array $body = '', array $options = []): HttpResponse {
        return static::request(static::POST, $url, $headers, $body, $options);
    }

    /**
     * Makes a PUT request.
     *
     * @param list<string> $headers Request headers
     * @param string|array<string, mixed> $body Raw body string, or an array sent as multipart/form-data
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function put(string $url, array $headers = [], string|array $body = '', array $options = []): HttpResponse {
        return static::request(static::PUT, $url, $headers, $body, $options);
    }

    /**
     * Makes a DELETE request.
     *
     * @param list<string> $headers Request headers
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function delete(string $url, array $headers = [], array $options = []): HttpResponse {
        return static::request(static::DELETE, $url, $headers, '', $options);
    }

    /**
     * Makes an OPTIONS request.
     *
     * @param list<string> $headers Request headers
     * @param array<string, mixed> $options Request options, see DEFAULTS and request()
     */
    public static function options(string $url, array $headers = [], array $options = []): HttpResponse {
        return static::request(static::OPTIONS, $url, $headers, '', $options);
    }

    /**
     * Makes an HTTP request using cURL.
     *
     * Only the final response's status line and headers are returned: interim responses
     * such as `100 Continue` and followed redirects are discarded. A body given with HEAD
     * is ignored.
     *
     * When following redirects, curl switches POST to GET on 301/302/303 as browsers do,
     * but re-sends PUT, DELETE, OPTIONS and other custom methods unchanged.
     *
     * @param list<string> $headers Request headers, e.g. ['Accept: application/json']
     * @param string|array<string, mixed> $body Raw body string, or an array sent as multipart/form-data
     * @param array<string, mixed> $options Overrides for DEFAULTS, plus `json`: a value sent
     *        as the JSON-encoded body (adds `Content-Type: application/json` unless a
     *        Content-Type header is given; can't be combined with `$body`)
     *
     * @throws InvalidArgumentException If the method is empty, an option is unknown or
     *                                  invalid, or both `$body` and `json` are given
     * @throws JsonException If the `json` value can't be encoded
     * @throws RuntimeException If the transfer fails (code is the cURL error number) or
     *                          the response has no status line
     */
    public static function request(string $method, string $url, array $headers = [], string|array $body = '', array $options = []): HttpResponse {

        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty');
        }

        $settings = self::resolveOptions($options);

        if (array_key_exists('json', $options)) {

            if ($body !== '' && $body !== []) {
                throw new InvalidArgumentException("Http option 'json' can't be combined with a request body");
            }

            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);

            $has_content_type = false;
            foreach ($headers as $header) {
                $has_content_type = $has_content_type || str_starts_with(strtolower(ltrim($header)), 'content-type:');
            }

            if (!$has_content_type) {
                $headers[] = 'Content-Type: application/json';
            }

        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $settings['connect_timeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $settings['timeout']);

        if ($settings['follow_redirects']) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, static::MAX_REDIRECTS);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $has_body = $body !== '' && $body !== [];

        // set the method with curl's dedicated options where they exist, so curl knows
        // a HEAD response has no body and can switch POST to GET on redirects
        if ($method === static::HEAD) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        elseif ($method === static::POST) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $has_body ? $body : '');
        }
        else {
            // a body makes curl send POST, so even GET needs the method set explicitly
            if ($method !== static::GET || $has_body) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            if ($has_body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        // collect header lines; each status line starts a new response (interim
        // responses, redirects) so only the final response's headers are kept
        $lines = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $handle, string $line) use (&$lines): int {
            $trimmed = rtrim($line, "\r\n");
            if (str_starts_with($trimmed, 'HTTP/')) {
                $lines = [$trimmed];
            }
            elseif ($trimmed !== '') {
                $lines[] = $trimmed;
            }
            return strlen($line);
        });

        $response = curl_exec($ch);

        if (curl_errno($ch) !== 0 || !is_string($response)) {
            throw new RuntimeException('Curl error: ' . curl_error($ch), curl_errno($ch));
        }

        if (!isset($lines[0]) || !str_starts_with($lines[0], 'HTTP/')) {
            throw new RuntimeException('Invalid HTTP response: no status line');
        }

        $status = static::parse_status_line($lines[0]);

        return new HttpResponse(
            $status['http_version'],
            $status['status_code'],
            $status['status_message'],
            self::parseHeaderLines(array_slice($lines, 1)),
            $response,
        );

    }

    /**
     * Parses HTTP headers into a structured array.
     *
     * - Header names are normalized to lowercase
     * - Duplicate headers are concatenated with commas
     * - The `set-cookie` header is returned as an array
     * - A first line starting `HTTP/` (status line) is parsed into the 'http' key
     * - Lines without a colon (blank lines, malformed headers) are skipped
     *
     * @param list<string>|string $headers Header lines, or a raw CRLF-separated header block
     *
     * @return array<string, mixed> Parsed headers with 'http' key containing status info
     */
    public static function parse_headers(array|string $headers): array {

        if (is_string($headers)) {
            $headers = explode("\r\n", $headers);
        }

        $headers = array_values($headers);
        $parsed  = [];

        if (isset($headers[0]) && str_starts_with($headers[0], 'HTTP/')) {
            $parsed['http'] = static::parse_status_line(array_shift($headers));
        }

        return $parsed + self::parseHeaderLines($headers);

    }

    /**
     * Parses the HTTP status line (first line of response).
     *
     * Accepts HTTP/1.x lines (`HTTP/1.1 200 OK`) and HTTP/2+ lines, which have a single
     * digit version and may omit the reason phrase (`HTTP/2 200`).
     *
     * @return array{
     *   http_version: string,
     *   status_code: int,
     *   status_message: string
     * }
     *
     * @throws RuntimeException If the status line format is invalid
     */
    public static function parse_status_line(string $status_line): array {

        // Status-Line = HTTP-Version SP Status-Code SP [ Reason-Phrase ]
        if (!preg_match('/^HTTP\/(\d+(?:\.\d+)?)\s+(\d{3})(?:\s+(.*))?$/', trim($status_line), $matches)) {
            throw new RuntimeException("Invalid status line: {$status_line}");
        }

        return [
            'http_version'   => $matches[1],
            'status_code'    => (int) $matches[2],
            'status_message' => $matches[3] ?? '',
        ];

    }

    /**
     * Merges request options over DEFAULTS and validates them. The `json` option is
     * allowed but handled by request().
     *
     * @param array<string, mixed> $options
     *
     * @return array{connect_timeout: int, timeout: int, follow_redirects: bool}
     *
     * @throws InvalidArgumentException If an option is unknown or has the wrong type
     */
    private static function resolveOptions(array $options): array {

        foreach (array_keys($options) as $key) {
            if ($key !== 'json' && !array_key_exists($key, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown Http option: {$key}");
            }
        }

        $connect_timeout  = $options['connect_timeout'] ?? self::DEFAULTS['connect_timeout'];
        $timeout          = $options['timeout'] ?? self::DEFAULTS['timeout'];
        $follow_redirects = $options['follow_redirects'] ?? self::DEFAULTS['follow_redirects'];

        if (!is_int($connect_timeout) || $connect_timeout < 0) {
            throw new InvalidArgumentException("Http option 'connect_timeout' must be a non-negative integer (seconds)");
        }

        if (!is_int($timeout) || $timeout < 0) {
            throw new InvalidArgumentException("Http option 'timeout' must be a non-negative integer (seconds)");
        }

        if (!is_bool($follow_redirects)) {
            throw new InvalidArgumentException("Http option 'follow_redirects' must be a boolean");
        }

        return [
            'connect_timeout'  => $connect_timeout,
            'timeout'          => $timeout,
            'follow_redirects' => $follow_redirects,
        ];

    }

    /**
     * Parses header lines (without a status line) into a lowercase-keyed array.
     *
     * @param list<string> $lines
     *
     * @return array<string, string|list<string>>
     */
    private static function parseHeaderLines(array $lines): array {

        $parsed = [];

        foreach ($lines as $line) {

            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $key   = strtolower(trim($key));
            $value = trim($value);

            // combine multiple headers with the same name
            // https://stackoverflow.com/questions/3241326/set-more-than-one-http-header-with-the-same-name
            if ($key === 'set-cookie') {
                $parsed[$key] = [...(array) ($parsed[$key] ?? []), $value];
            }
            elseif (isset($parsed[$key]) && is_string($parsed[$key])) {
                $parsed[$key] .= ',' . $value;
            }
            else {
                $parsed[$key] = $value;
            }

        }

        return $parsed;

    }

}
