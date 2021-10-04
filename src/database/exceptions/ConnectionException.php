<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\exceptions;

use Exception;

/**
 * Thrown if a database connection could not be established.
 */
class ConnectionException extends DatabaseException {

    public function __construct( string $message = 'An error occurred attempting to connect to the database', $code = 0, Exception $previous = null ) {
        parent::__construct($message, $code, $previous);
    }

}
