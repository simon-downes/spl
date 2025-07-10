<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Exception;

/**
 * HTTP error exceptions with appropriate status codes.
 *
 * Provides factory methods for common HTTP errors (400, 401, 403, 404, 405).
 * The exception code is used as the HTTP status code in responses.
 */
class WebException extends Exception {

    /**
     * Creates a 400 Bad Request exception.
     */
    public static function badRequest(string $message): static {
        return new static($message, 400);
    }

    /**
     * Creates a 401 Unauthorized exception.
     */
    public static function unauthorized(string $message): static {
        return new static($message, 401);
    }

    /**
     * Creates a 403 Forbidden exception.
     */
    public static function forbidden(string $message): static {
        return new static($message, 403);
    }

    /**
     * Creates a 404 Not Found exception.
     */
    public static function notFound(string $path): static {
        return new static("Not Found: {$path}", 404);
    }

    /**
     * Creates a 405 Method Not Allowed exception.
     */
    public static function methodNotAllowed(string $method, string $path): static {
        return new static("Method Not Allowed: {$method} for {$path}", 405);
    }

    public function __construct(string $message, int $code) {
        parent::__construct($message, $code, null);
    }

}
