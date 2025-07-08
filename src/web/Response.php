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
 * HTTP response class.
 * 
 * Represents an HTTP response with methods to set status, headers, cookies, and body.
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
     * Create a response from a Throwable.
     *
     * @param Throwable $e The throwable to create a response from
     * 
     * @return static A new Response instance
     */
    public static function fromThrowable(Throwable $e): static {

        $code = $e instanceof WebException ? $e->getCode() : 500;

        // TOOD: attempt to render a view
        $body = sprintf('<h1>%s</h1>', $e->getMessage());

        return (new static($code, $body));

    }

    /**
     * Create a redirect response.
     *
     * @param string $url       The URL to redirect to
     * @param bool   $permanent Whether the redirect is permanent
     * 
     * @return static A new Response instance
     */
    public static function redirect(string $url, bool $permanent = false): static {
        return (new static($permanent ? 301 : 302))->setHeader('location', $url);
    }

    /**
     * Create a JSON response.
     *
     * @param array $data   The data to encode as JSON
     * @param int   $status The HTTP status code
     * 
     * @return static A new Response instance
     */
    public static function json(array $data, int $status = 200): static {
        return new static($status, json_encode($data), 'application/json');
    }

    /**
     * Create an HTML response.
     *
     * @param string $body   The HTML body
     * @param int    $status The HTTP status code
     * 
     * @return static A new Response instance
     */
    public static function html(string $body, int $status = 200): static {
        return new static($status, $body);
    }

    /**
     * Create a plain text response.
     *
     * @param string $body   The text body
     * @param int    $status The HTTP status code
     * 
     * @return static A new Response instance
     */
    public static function text(string $body, int $status = 200): static {
        return new static($status, $body, 'text/plain');
    }

    /**
     * Create a new Response instance.
     *
     * @param int    $status       The HTTP status code
     * @param string $body         The response body
     * @param string $content_type The content type
     * 
     * @throws Exception If the status code is invalid
     */
    public function __construct(int $status, string $body = '', string $content_type = 'text/html') {

        $this->headers = [];
        $this->cookies = [];

        $this
            ->setStatus($status)
            ->setBody($body, $content_type)
        ;

    }

    /**
     * Magic method to handle getters.
     *
     * @param string $name      The method name
     * @param array  $arguments The method arguments
     * 
     * @return mixed The property value
     * 
     * @throws BadMethodCallException If the method is not a valid getter
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

    /**
     * Check if the response is a redirect.
     *
     * @return bool True if the response is a redirect
     */
    public function isRedirect(): bool {
        return in_array($this->status['code'], [301, 302, 303, 307, 308], true);
    }

    /**
     * Check if the response is a server error.
     *
     * @return bool True if the response is a server error
     */
    public function isServerError(): bool {
        return $this->status['code'] >= 500;
    }

    /**
     * Set the HTTP status code and message.
     *
     * @param int    $code    The HTTP status code
     * @param string $message The status message (optional)
     * 
     * @return static This response instance
     * 
     * @throws Exception If the status code is invalid
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
     * Set the response body.
     *
     * @param string $body         The response body
     * @param string $content_type The content type
     * @param string $charset      The character set
     * 
     * @return static This response instance
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
     * Set a response header.
     *
     * @param string      $name  The header name
     * @param string|null $value The header value (null to remove)
     * 
     * @return static This response instance
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
     * Set a cookie.
     *
     * @param string      $name    The cookie name
     * @param string|null $value   The cookie value (null to remove)
     * @param int         $expires The cookie expiration time in seconds
     * @param string      $path    The cookie path
     * @param string      $domain  The cookie domain
     * 
     * @return static This response instance
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

    /**
     * Send the response to the client.
     *
     * @return void
     */
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
