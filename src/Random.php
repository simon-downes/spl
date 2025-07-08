<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use InvalidArgumentException;

/**
 * Random data generation utility class.
 * 
 * Provides methods for generating random strings.
 */
class Random {

    /**
     * Returns a string of cryptographically strong random hex digits.
     *
     * @param int $length The length of the hex string (must be even)
     * 
     * @return string The random hex string
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
     * Returns a string of the specified length containing only the characters in the $allowed parameter.
     * This function is not cryptographically strong.
     *
     * @param int    $length  Length of the desired string
     * @param string $allowed The characters allowed to appear in the output
     * 
     * @return string The random string
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
