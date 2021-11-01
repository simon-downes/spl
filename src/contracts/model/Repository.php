<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\model;

interface Repository {

    public function fetch( int|string $id ): Model;

    public function save( Model $model ): void;

    public function delete( Model $model ): void;

}
