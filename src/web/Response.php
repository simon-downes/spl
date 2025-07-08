<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Throwable;
use Exception;
use BadMethodCallException;

class Response {

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
     */
    protected array $status;

    /**
     * HTTP headers.
     */
    protected array $headers;

    /**
     * Cookie array.
     */
    protected array $cookies;

    /**
     * The main body of the response.
     */
    protected string $body;

    public static function fromThrowable(Throwable $e): static {

        $code = $e instanceof WebException ? $e->getCode() : 500;

        // TOOD: attempt to render a view
        $body = sprintf('<h1>%s</h1>', $e->getMessage());

        return (new static($code, $body));

    }

    public static function redirect($url, $permanent = false): static {
        return (new static($permanent ? 301 : 302))->setHeader('location', $url);
    }

    public static function json(array $data, int $status = 200): static {
        return new static($status, json_encode($data), 'application/json');
    }

    public static function html(string $body, int $status = 200): static {
        return new static($status, $body);
    }

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

    public function __call($name, $arguments) {

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

    public function isRedirect() {
        return in_array($this->status['code'], [301, 302, 303, 307, 308], true);
    }

    public function isServerError() {
        return $this->status['code'] >= 500;
    }

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

    public function setBody(string $body, string $content_type = 'text/html', $charset = 'UTF-8') {

        $this->body = $body;

        if (empty($charset)) {
            $charset = mb_detect_encoding($this->body);
        }

        $this->headers['content-type'] = "{$content_type}; charset={$charset}";
        $this->headers['content-length'] = strlen($this->body);

        return $this;

    }

    public function setHeader($name = null, $value = null): static {

        // normalise header names
        $name = strtolower($name);

        // if null then unset header
        if ($value === null) {
            unset($this->headers[$name]);
        }
        else {
            $this->headers[$name] = $value;
        }

        return $this;

    }

    public function setCookie($name = null, $value = null, $expires = 0, $path = '/', $domain = ''): static {

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
