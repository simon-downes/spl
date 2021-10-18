<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\database;

use Stringable;

interface Query extends Stringable {

    public function getParameters(): array;

    public function setParameters( array $params, $replace = false ): Query;

}
