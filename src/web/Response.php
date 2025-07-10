<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Throwable;
use Exception;
use BadMethodCallException;

/**
 * HTTP response representation with fluent interface for configuration.
 *
 * Provides factory methods for common response types (HTML, JSON, redirects)
 * and a fluent interface for setting headers, cookies, and other properties.
 *
 * Usage:
 *   $response = Response::html('<h1>Hello World</h1>');
 *   $response = Response::json(['status' => 'success']);
 *   $response = Response::redirect('/new-location');
 */
class Response {

    /**
     * HTTP status codes and their messages.
     */
    protected const STATUSES = [
        // info 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',

        // success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',

        // redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',

        // client error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',

        // server error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        509 => 'Bandwidth Limit Exceeded',
    ];

    /**
     * HTTP status code and message.
     *
     * @var array{code: int, message: string}
     */
    protected array $status;

    /**
     * HTTP headers.
     *
     * @var array<string, string>
     */
    protected array $headers;

    /**
     * Cookie array.
     *
     * @var array<string, array{value: string, expires: int, path: string, domain: string}>
     */
    protected array $cookies;

    /**
     * The main body of the response.
     *
     * @var string
     */
    protected string $body;

    /**
     * Creates an appropriate response from an exception.
     *
     * Uses the exception's code as HTTP status if it's a WebException,
     * otherwise defaults to 500 Internal Server Error.
     */
    public static function fromThrowable(Throwable $e): static {

        $code = $e instanceof WebException ? $e->getCode() : 500;

        // TOOD: attempt to render a view
        $body = sprintf('<h1>%s</h1>', $e->getMessage());

        return (new static($code, $body));

    }

    /**
     * Creates a redirect response (302 Found or 301 Moved Permanently).
     */
    public static function redirect(string $url, bool $permanent = false): static {
        return (new static($permanent ? 301 : 302))->setHeader('location', $url);
    }

    /**
     * Creates a JSON response with appropriate content-type header.
     *
     * @param array<mixed> $data Data to be JSON encoded
     */
    public static function json(array $data, int $status = 200): static {
        return new static($status, json_encode($data), 'application/json');
    }

    /**
     * Creates an HTML response (content-type: text/html).
     */
    public static function html(string $body, int $status = 200): static {
        return new static($status, $body);
    }

    /**
     * Creates a plain text response (content-type: text/plain).
     */
    public static function text(string $body, int $status = 200): static {
        return new static($status, $body, 'text/plain');
    }

    public function __construct(int $status, string $body = '', string $content_type = 'text/html') {

        $this->headers = [];
        $this->cookies = [];

        $this
            ->setStatus($status)
            ->setBody($body, $content_type)
        ;

    }

    /**
     * Magic getter method that handles:
     * - getStatus(), getBody(), getHeaders(), getCookies()
     * - getHeader($name), getCookie($name)
     *
     * @throws BadMethodCallException For invalid getter methods
     */
    public function __call(string $name, array $arguments): mixed {

        if (str_starts_with($name, 'get')) {

            $property = substr(strtolower($name), 3);

            switch ($property) {

                case 'status':
                case 'body':
                case 'headers':
                case 'cookies':
                    /** @phpstan-ignore-next-line */
                    return $this->$property;

                case 'header':
                    return $this->headers[strtolower($arguments[0])] ?? null;

                case 'cookie':
                    return $this->cookies[$arguments[0]] ?? null;

            }

        }

        throw new BadMethodCallException(sprintf("Unknown method %s::%s", get_class($this), $name));

    }

    public function isRedirect(): bool {
        return in_array($this->status['code'], [301, 302, 303, 307, 308], true);
    }

    public function isServerError(): bool {
        return $this->status['code'] >= 500;
    }

    /**
     * Sets the HTTP status code and message.
     *
     * @throws Exception If the status code is not defined in the STATUSES constant
     */
    public function setStatus(int $code, string $message = ''): static {

        if (!isset(static::STATUSES[$code])) {
            throw new Exception("{$code} is not a valid HTTP status code");
        }

        $this->status = [
            'code'    => $code,
            'message' => $message ? $message : static::STATUSES[$code],
        ];

        return $this;

    }

    /**
     * Sets the response body and appropriate content-type header.
     *
     * If charset is empty, attempts to detect encoding from the body.
     */
    public function setBody(string $body, string $content_type = 'text/html', string $charset = 'UTF-8'): static {

        $this->body = $body;

        if (empty($charset)) {
            $charset = mb_detect_encoding($this->body);
        }

        $this->headers['content-type'] = "{$content_type}; charset={$charset}";
        $this->headers['content-length'] = strlen($this->body);

        return $this;

    }

    /**
     * Sets a response header (or removes it if value is null).
     *
     * Header names are normalized to lowercase.
     */
    public function setHeader(string $name, ?string $value = null): static {

        // normalise header names
        $name = strtolower($name);

        // if null then unset header
        if ($value === null) {
            unset($this->headers[$name]);
            return $this;
        }

        $this->headers[$name] = $value;

        return $this;

    }

    /**
     * Sets a cookie (or removes it if value is null).
     *
     * The expires parameter is in seconds from now, not a timestamp.
     */
    public function setCookie(string $name, ?string $value = null, int $expires = 0, string $path = '/', string $domain = ''): static {

        // if the value is null then unset the cookie
        if ($value === null) {
            unset($this->cookies[$name]);
            return $this;
        }

        $this->cookies[$name] = [
            'value'   => $value,
            'expires' => $expires ? time() + $expires : 0,
            'path'    => $path,
            'domain'  => $domain,
        ];

        return $this;

    }

    public function send(): void {

        header("HTTP/1.1 {$this->status['code']} {$this->status['message']}");

        foreach ($this->headers as $name => $value) {
            header("{$name}: $value");
        }

        foreach ($this->cookies as $name => $cookie) {
            setcookie($name, $cookie['value'], $cookie['expires'], $cookie['path'], $cookie['domain']);
        }

        echo $this->body;

    }

}

// EOF
