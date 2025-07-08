<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\web;

use Exception;

class WebException extends Exception {

    public static function badRequest(string $message): static {
        return new static($message, 400);
    }

    public static function unauthorized(string $message): static {
        return new static($message, 401);
    }

    public static function forbidden(string $message): static {
        return new static($message, 403);
    }

    public static function notFound(string $path): static {
        return new static("Not Found: {$path}", 404);
    }

    public static function methodNotAllowed(string $method, string $path): static {
        return new static("Method Not Allowed: {$method} for {$path}", 405);
    }

    public function __construct(string $message, int $code) {
        parent::__construct($message, $code, null);
    }

}
