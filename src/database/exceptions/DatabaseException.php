<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\exceptions;

use Exception;

use spl\contracts\Exception as SplException;

/**
 * Base database exception.
 */
class DatabaseException extends Exception implements SplException {

    /**
     * https://bugs.php.net/bug.php?id=51742
     */
    protected $code;

    public function __construct( string $message = 'An unknown database error occurred', int|string $code = 0, Exception $previous = null ) {
        parent::__construct($message, (int) $code, $previous);
        $this->code = $code;
    }

}
