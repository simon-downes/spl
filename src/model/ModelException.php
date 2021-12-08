<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\model;

use RuntimeException;

use spl\contracts\Exception as SplException;

class ModelException extends Exception implements SplException {

}
