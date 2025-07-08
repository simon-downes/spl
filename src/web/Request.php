<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

/**
 * HTTP request class.
 * 
 * Represents an HTTP request with methods to access request data.
 */
class Request {

    /**
     * Create a new Request instance from global variables.
     *
     * @return static A new Request instance
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
        ], function($value) { return $value !== false; });

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
     * Create a new Request instance.
     *
     * @param string     $method    The HTTP method
     * @param string     $host      The host name
     * @param string     $path      The request path
     * @param array      $query     The query parameters
     * @param mixed      $body      The request body
     * @param array      $headers   The request headers
     * @param array      $cookies   The request cookies
     * @param string     $client_ip The client IP address
     * @param array      $attributes Additional request attributes
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

    /**
     * Get the host name.
     *
     * @return string The host name
     */
    public function getHost(): string {
        return $this->host;
    }

    /**
     * Get the HTTP method.
     *
     * @return string The HTTP method
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Get the request path.
     *
     * @return string The request path
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Look up a value in the query parameters or request body.
     *
     * @param string $k       The key to look up
     * @param mixed  $default The default value if the key doesn't exist
     * 
     * @return mixed The value or the default
     */
    public function lookup(string $k, mixed $default = null): mixed {
        return $this->query[$k] ?? $this->body[$k] ?? $default;
    }

    /**
     * Get the request body.
     *
     * @return mixed The request body
     */
    public function getBody(): mixed {
        return $this->body;
    }

    /**
     * Get a request header.
     *
     * @param string $name The header name
     * 
     * @return string The header value or an empty string if not found
     */
    public function getHeader(string $name): string {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Get a cookie value.
     *
     * @param string $name The cookie name
     * 
     * @return string The cookie value or an empty string if not found
     */
    public function getCookie(string $name): string {
        return $this->cookies[$name] ?? '';
    }

    /**
     * Get the client IP address.
     *
     * @return string The client IP address
     */
    public function getSourceIP(): string {
        return $this->client_ip;
    }

    /**
     * Get a request attribute.
     *
     * @param string $name    The attribute name
     * @param mixed  $default The default value if the attribute doesn't exist
     * 
     * @return mixed The attribute value or the default
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
