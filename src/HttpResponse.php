<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use JsonException;

/**
 * Immutable response returned by the Http client.
 *
 * Holds the status line and headers of the final response (interim and redirect
 * responses are discarded) and the raw response body.
 *
 * Usage:
 *   $response = Http::get('https://api.example.com/users');
 *   if ($response->ok()) {
 *       $users = $response->json();
 *   }
 */
final readonly class HttpResponse {

    /**
     * @param string $http_version   Protocol version from the status line, e.g. "1.1" or "2"
     * @param int    $status_code    HTTP status code
     * @param string $status_message Reason phrase, empty when the server sends none (HTTP/2)
     * @param array<string, string|list<string>> $headers Lowercased header names; repeated
     *        headers are joined with commas, except `set-cookie` which is a list
     * @param string $body Raw response body, empty for HEAD and bodiless responses
     */
    public function __construct(
        public string $http_version,
        public int $status_code,
        public string $status_message,
        public array $headers,
        public string $body,
    ) {}

    /**
     * Returns true if the status code is in the 2xx range.
     */
    public function ok(): bool {
        return $this->status_code >= 200 && $this->status_code <= 299;
    }

    /**
     * Decodes the body as JSON, with objects returned as associative arrays.
     *
     * @throws JsonException If the body is not valid JSON (including an empty body)
     */
    public function json(): mixed {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

}
