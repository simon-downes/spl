<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\support;

interface Dumpable {

    /**
     * Return a string containing a debug representation of the object.
     */
    public function dump(): string;

}
