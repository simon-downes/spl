<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use InvalidArgumentException;

class Random {

    /**
     * Returns a string of cryptographically strong random hex digits.
     */
    public static function hex(int $length = 40): string {

        if (($length % 2) == 1) {
            throw new InvalidArgumentException("Hex strings must be an even number in length");
        }

        $hex = bin2hex(random_bytes((int) ceil($length / 2)));

        return $hex;

    }

    /**
     * Returns a string of the specified length containing only the characters in the $allowed parameter.
     * This function is not cryptographically strong.
     *
     * @param  int  $length    length of the desired string
     * @param  string  $allowed   the characters allowed to appear in the output
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
