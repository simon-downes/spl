<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use InvalidArgumentException;

/**
 * Utility for generating random strings.
 *
 * Provides both cryptographically secure and non-secure random string generation.
 */
class Random {

    /**
     * Generates cryptographically strong random hex digits.
     *
     * @throws InvalidArgumentException If the length is not an even number
     */
    public static function hex(int $length = 40): string {

        if (($length % 2) == 1) {
            throw new InvalidArgumentException("Hex strings must be an even number in length");
        }

        $hex = bin2hex(random_bytes(max(1, (int) ceil($length / 2))));

        return $hex;

    }

    /**
     * Generates a random string using the specified character set.
     *
     * Uses mt_rand() so this is NOT cryptographically secure.
     */
    public static function string(int $length, string $allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string {

        $out = '';
        $max = strlen($allowed) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= $allowed[mt_rand(0, $max)];
        }

        return $out;

    }

}
