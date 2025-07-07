<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

class Request {

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
        ]);

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

    public function lookup($k, mixed $default = null): mixed {
        return $this->query[$k] ?? $this->body[$k] ?? $default;
    }

    public function getBody(): mixed {
        return $this->body;
    }

    public function getHeader(string $name): string {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function getCookie(string $name): string {
        return $this->cookies[$name] ?? '';
    }

    public function getSourceIP(): string {
        return $this->client_ip;
    }

    public function getAttribute(string $name, mixed $default = null): mixed {
        return $this->attributes[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): void {
        $this->attributes[$name] = $value;
    }

}
