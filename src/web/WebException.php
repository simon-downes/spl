<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Exception;

/**
 * Web-specific exception class.
 * 
 * Provides factory methods for common HTTP error responses.
 */
class WebException extends Exception {

    /**
     * Create a 400 Bad Request exception.
     *
     * @param string $message The error message
     * 
     * @return static A new WebException instance
     */
    public static function badRequest(string $message): static {
        return new static($message, 400);
    }

    /**
     * Create a 401 Unauthorized exception.
     *
     * @param string $message The error message
     * 
     * @return static A new WebException instance
     */
    public static function unauthorized(string $message): static {
        return new static($message, 401);
    }

    /**
     * Create a 403 Forbidden exception.
     *
     * @param string $message The error message
     * 
     * @return static A new WebException instance
     */
    public static function forbidden(string $message): static {
        return new static($message, 403);
    }

    /**
     * Create a 404 Not Found exception.
     *
     * @param string $path The path that was not found
     * 
     * @return static A new WebException instance
     */
    public static function notFound(string $path): static {
        return new static("Not Found: {$path}", 404);
    }

    /**
     * Create a 405 Method Not Allowed exception.
     *
     * @param string $method The HTTP method that was not allowed
     * @param string $path   The path that was requested
     * 
     * @return static A new WebException instance
     */
    public static function methodNotAllowed(string $method, string $path): static {
        return new static("Method Not Allowed: {$method} for {$path}", 405);
    }

    /**
     * Create a new WebException instance.
     *
     * @param string $message The error message
     * @param int    $code    The HTTP status code
     */
    public function __construct(string $message, int $code) {
        parent::__construct($message, $code, null);
    }

}
