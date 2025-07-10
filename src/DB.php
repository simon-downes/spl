<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl;

use BadMethodCallException;
use RuntimeException;

use spl\database\Connection;

/**
 * Static facade for database operations.
 *
 * Provides a convenient static interface to database operations by lazily creating
 * a database connection from environment variables when first needed.
 *
 * Usage:
 *   DB::query("SELECT * FROM users WHERE id = ?", [1]);
 *   DB::getRow("SELECT * FROM users WHERE id = ?", [1]);
 */
class DB {

    /**
     * Lazily initialized database connection
     */
    protected static Connection $db;

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Forwards all method calls to the database connection.
     *
     * Lazily creates a connection from DB_DSN environment variable if needed.
     *
     * @throws RuntimeException If DB_DSN environment variable is not set
     * @throws BadMethodCallException If the method doesn't exist on the connection
     */
    public static function __callStatic(string $name, array $arguments): mixed {

        // no instance defined so create it
        if (empty(static::$db)) {

            $dsn = (string) env('DB_DSN');

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
