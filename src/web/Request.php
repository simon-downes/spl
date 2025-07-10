<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

/**
 * HTTP request representation with convenient access methods.
 *
 * Provides access to request data including path, query parameters,
 * headers, cookies, and body with automatic JSON parsing.
 */
class Request {

    /**
     * Creates a Request instance from PHP's global variables.
     *
     * Extracts data from $_SERVER, $_GET, $_POST, $_COOKIE, etc.
     */
    public static function fromGlobals(): static {

        $query = [];

        if ($_SERVER['QUERY_STRING']) {
            parse_str($_SERVER['QUERY_STRING'], $query);
        }

        $headers = [];

        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                // strip HTTP_ prefix, replace _ with -
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
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
            $_SERVER['HTTP_HOST'],
            str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']),
            $query,
            $body,
            $headers,
            $_COOKIE,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $auth ? ['auth' => $auth] : [],
        );

    }

    /**
     * Creates a new Request instance with the specified parameters.
     *
     * Normalizes headers to lowercase and automatically parses JSON bodies.
     *
     * @param array<string, string> $query      Query parameters from URL
     * @param mixed                 $body       Request body (string or parsed array)
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

        if (is_string($this->body) && strtolower($this->getHeader('content-type')) == 'application/json') {
            $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
            if ($decoded) {
                $this->body = $decoded;
            }
        }

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
     */
    public function lookup(string $k, mixed $default = null): mixed {
        return $this->query[$k] ?? $this->body[$k] ?? $default;
    }

    public function getBody(): mixed {
        return $this->body;
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
