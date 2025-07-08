<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use BadMethodCallException;
use RuntimeException;

use spl\database\Connection;

class DB {

    protected static Connection $db;

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    public static function __callStatic($name, $arguments) {

        // no instance defined so create it
        if (empty(static::$db)) {

            $dsn = env('DB_DSN');

            if (empty($dsn)) {
                throw new RuntimeException("Missing value for DB_DSN environment variable");
            }

            static::$db = new Connection($dsn);

        }

        if (!method_exists(static::$db, $name)) {
            throw new BadMethodCallException(sprintf("Unknown method %s::%s", static::$db::class, $name));
        }

        /** @phpstan-ignore-next-line */
        return static::$db->$name(...$arguments);

    }

}
