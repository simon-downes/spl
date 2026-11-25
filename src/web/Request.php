<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use JsonException;

/**
 * HTTP request representation with convenient access methods.
 *
 * Provides access to request data including path, query parameters,
 * headers, cookies, and body with automatic JSON parsing.
 */
class Request {

    /**
     * Whether the body has been checked for, and if needed decoded from, JSON.
     */
    protected bool $body_decoded = false;

    /**
     * Creates a Request instance from PHP's global variables.
     *
     * Extracts data from $_SERVER, $_GET, $_POST, $_COOKIE, etc.
     */
    public static function fromGlobals(): static {

        $query = [];

        $query_string = (string) ($_SERVER['QUERY_STRING'] ?? '');

        if ($query_string !== '') {
            parse_str($query_string, $query);
        }

        $headers = [];

        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                // strip HTTP_ prefix, replace _ with -
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }

        // Apache and other CGI-style SAPIs only provide these without the HTTP_ prefix
        foreach (['CONTENT_TYPE' => 'CONTENT-TYPE', 'CONTENT_LENGTH' => 'CONTENT-LENGTH'] as $k => $name) {
            if (isset($_SERVER[$k]) && !isset($headers[$name])) {
                $headers[$name] = $_SERVER[$k];
            }
        }

        $body = $_POST;

        if (empty($body)) {
            $body = file_get_contents("php://input");
        }

        $auth = array_filter([
            'username' => $_SERVER['PHP_AUTH_USER'] ?? false,
            'password' => $_SERVER['PHP_AUTH_PW'] ?? false,
        ], function ($value) { return $value !== false; });

        return new static(
            $_SERVER['REQUEST_METHOD'],
            (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
            static::parsePath((string) ($_SERVER['REQUEST_URI'] ?? '')),
            $query,
            $body,
            $headers,
            $_COOKIE,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $auth ? ['auth' => $auth] : [],
        );

    }

    /**
     * Extracts the path component of a request URI, without query string or fragment.
     *
     * Origin-form URIs (starting with "/") are parsed against a dummy origin so that
     * paths such as "/a:1" or "//a/b" aren't mistaken for a port or a host.
     * Returns "/" if no path can be determined.
     *
     * Note that parse_url() replaces ASCII control characters with "_", so a raw
     * "/a\x01b" becomes "/a_b" (percent-encoded "%01" is left as is).
     */
    protected static function parsePath(string $uri): string {

        $path = parse_url(str_starts_with($uri, '/') ? "http://localhost{$uri}" : $uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';

    }

    /**
     * Creates a new Request instance with the specified parameters.
     *
     * Normalizes headers to lowercase. A string body sent with an application/json
     * content-type is decoded on first access by getBody() or lookup().
     *
     * @param array<string, string> $query      Query parameters from URL
     * @param mixed                 $body       Request body (raw string or parsed array)
     * @param array<string, string> $headers    HTTP headers
     * @param array<string, string> $cookies    Request cookies
     * @param array<string, mixed>  $attributes Additional request metadata
     */
    public function __construct(
        protected string $method,
        protected string $host,
        protected string $path,
        protected array $query = [],
        protected mixed $body = '',
        protected array $headers = [],
        protected array $cookies = [],
        protected string $client_ip = '',
        protected array $attributes = [],
    ) {

        // normalise header names to lowercase
        $this->headers = array_combine(
            array_map('strtolower', array_keys($this->headers)),
            array_values($this->headers),
        );

        ksort($this->headers);
        ksort($this->cookies);

        $forwarded_for = explode(',', $this->getHeader('x-forwarded-for'));

        if (!empty($forwarded_for[0])) {
            $this->client_ip = trim($forwarded_for[0]);
        }

    }

    public function getHost(): string {
        return $this->host;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getPath(): string {
        return $this->path;
    }

    /**
     * Looks up a value in query parameters first, then request body.
     *
     * The body is only read (and decoded if JSON) when the key isn't in the query.
     * Returns the default if the body isn't an array (e.g. empty, raw or scalar JSON).
     *
     * @throws WebException 400 Bad Request if the body is JSON and is invalid
     */
    public function lookup(string $k, mixed $default = null): mixed {

        if (isset($this->query[$k])) {
            return $this->query[$k];
        }

        $body = $this->getBody();

        return is_array($body) ? $body[$k] ?? $default : $default;

    }

    /**
     * Gets the request body.
     *
     * A string body with an application/json media type (parameters such as charset
     * are ignored) is decoded on the first call, objects as associative arrays, and
     * the decoded value is returned from then on. An empty or whitespace-only body
     * counts as no body and is returned as is, whatever the content-type.
     *
     * @throws WebException 400 Bad Request if the body is JSON and is invalid
     */
    public function getBody(): mixed {

        if (!$this->body_decoded) {

            // only JSON's own whitespace characters, so a body like "\0" is still invalid JSON
            if (is_string($this->body) && trim($this->body, " \t\n\r") !== '' && $this->isJson()) {
                try {
                    $this->body = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
                }
                catch (JsonException $e) {
                    throw WebException::badRequest("Invalid JSON request body: {$e->getMessage()}");
                }
            }

            $this->body_decoded = true;

        }

        return $this->body;

    }

    /**
     * Checks whether the request's content-type media type is application/json.
     */
    protected function isJson(): bool {

        $media_type = explode(';', $this->getHeader('content-type'), 2)[0];

        return strtolower(trim($media_type)) === 'application/json';

    }

    /**
     * Gets a request header (case-insensitive).
     *
     * Returns empty string if header doesn't exist.
     */
    public function getHeader(string $name): string {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Gets a cookie value.
     *
     * Returns empty string if cookie doesn't exist.
     */
    public function getCookie(string $name): string {
        return $this->cookies[$name] ?? '';
    }

    public function getSourceIP(): string {
        return $this->client_ip;
    }

    /**
     * Gets a request attribute with optional default value.
     *
     * Attributes are additional metadata attached to the request.
     */
    public function getAttribute(string $name, mixed $default = null): mixed {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Set a request attribute.
     *
     * @param string $name  The attribute name
     * @param mixed  $value The attribute value
     *
     * @return void
     */
    public function setAttribute(string $name, mixed $value): void {
        $this->attributes[$name] = $value;
    }

}
