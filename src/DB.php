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
 *
 * @method static void reconnect() Re-establish the database connection
 * @method static \stdClass getDSN() Get a copy of the connection details
 * @method static \PDOStatement prepare(string|\PDOStatement $statement) Prepare a SQL statement with caching
 * @method static \PDOStatement query(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Execute a SQL query
 * @method static int execute(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Execute a SQL statement and return the number of affected rows
 * @method static array<int, array<string, mixed>> getAll(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get all result rows
 * @method static array<int, array<int, array<string, mixed>>> getAllMulti(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get all rows from every result set
 * @method static array<string|int, mixed> getAssoc(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get rows keyed by the first column
 * @method static array<string|int, array<string|int, mixed>> getAssocMulti(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get rows keyed by the first two columns
 * @method static array<string, mixed> getRow(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get the first row, or an empty array
 * @method static array<int, mixed> getCol(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get the first column of all rows
 * @method static mixed getOne(string|\PDOStatement $statement, array<string|int, mixed> $params = []) Get the first column of the first row, or null
 * @method static int rawExec(string $sql) Execute a raw SQL string (DDL only, never user input)
 * @method static bool begin() Begin a transaction
 * @method static bool commit() Commit the current transaction
 * @method static bool rollback() Roll back the current transaction
 * @method static bool inTransaction() Check if a transaction is active
 * @method static string insertId(string $name = '') Get the last insert ID
 * @method static string quote(mixed $value, int $type = \PDO::PARAM_STR) Quote a value for use in SQL
 * @method static string quoteIdentifier(string $name) Quote a table or column name
 * @method static array<int, mixed> getLastError() Get the last error information
 * @method static array{dsn: \stdClass, stats: \stdClass, driver: string, client: string, server: string} getInfo() Get connection information
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
