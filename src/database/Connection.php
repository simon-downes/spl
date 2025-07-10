<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\database;

use stdClass;
use Closure;
use PDO;
use PDOStatement;
use RuntimeException;

use spl\Str;

/**
 * Enhanced PDO wrapper with simplified query methods.
 *
 * Provides convenient methods for common database operations:
 * - Simplified query execution with parameter binding
 * - Fetching single rows, columns, or values
 * - Transaction management
 * - Support for MySQL, PostgreSQL, SQLite, and SQL Server
 */
class Connection {

    /**
     * Database type constants.
     */
    public const TYPE_MYSQL      = 'mysql';
    public const TYPE_POSTGRES   = 'pgsql';
    public const TYPE_SQLITE     = 'sqlite';
    public const TYPE_SQL_SERVER = 'sqlsrv';

    /**
     * The PDO database connection.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Connection details.
     *
     * @var stdClass
     */
    protected stdClass $dsn;

    /**
     * Prepared statement cache.
     *
     * @var array<string, PDOStatement>
     */
    protected array $statements = [];

    /**
     * Some basic stats on timings and query count.
     *
     * @var stdClass
     */
    protected stdClass $stats;

    /**
     * Creates a new database connection from a URL.
     *
     * URL format: driver://username:password@host:port/database?option=value
     *
     * @throws RuntimeException If the URL is invalid or the connection fails
     */
    public function __construct(string $url) {

        $this->dsn = $this->makeDSN(($url));

        $this->stats = (object) [
            'prepare_time' => 0.00,
            'query_time'   => 0.00,
            'query_count'  => 0,
        ];

        $this->reconnect();

    }

    /**
     * Take a URL string and return an object containing the individual parts.
     *
     * @param string $url The database connection URL
     *
     * @return stdClass The parsed DSN object
     *
     * @throws RuntimeException If the URL is invalid or the database type is unknown
     */
    protected function makeDSN(string $url): stdClass {

        $parts = Str::parseURL($url);

        // no point continuing if it went wrong
        if (empty($parts)) {
            throw new RuntimeException("Invalid database configuration: {$url}");
        }

        $dsn = (object) [
            'type'    => $parts['scheme'],
            'host'    => $parts['host'],
            'port'    => $parts['port'],
            'user'    => $parts['user'],
            'pass'    => $parts['pass'],
            'db'      => trim($parts['path'], '/'),
            'options' => $parts['query'],
        ];

        if (empty($dsn->db)) {
            throw new RuntimeException("No database name specified");
        }

        // create a PDO DSN string depending on the database type
        switch ($dsn->type) {

            case static::TYPE_MYSQL:
                $dsn->port = $dsn->port ?: 3306;
                $dsn->pdo  = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $dsn->host, $dsn->port, $dsn->db);
                break;

            case static::TYPE_POSTGRES:
                $dsn->port = $dsn->port ?: 5432;
                $dsn->pdo  = sprintf("pgsql:host=%s;port=%s;dbname=%s", $dsn->host, $dsn->port, $dsn->db);
                break;

            case static::TYPE_SQL_SERVER:
                $dsn->port = $dsn->port ?: 1433;
                $dsn->pdo  = sprintf('sqlsrv:Server=%s,%s;Database=%s', $dsn->host, $dsn->port, $dsn->db);
                break;

            case static::TYPE_SQLITE:

                // these should always be null as they're invalid for SQLite connections
                $dsn->host = 'localhost';
                $dsn->port = null;
                $dsn->user = null;
                $dsn->pass = null;

                $dsn->pdo = sprintf(
                    'sqlite:%s',
                    (defined('SPL_ROOT') ? SPL_ROOT . '/' : '') . $dsn->db,
                );

                break;

            default:
                throw new RuntimeException("Unknown database type specified: '{$dsn->type}'");

        }

        return $dsn;

    }

    /**
     * Re-establishes the database connection.
     *
     * Useful after calling pcntl_fork() or similar operations.
     * Clears the statement cache and creates a new PDO instance.
     *
     * Note: This will have no effect if references to the underlying PDO
     * instance exist outside of this Connection instance.
     */
    public function reconnect(): void {

        // destroy the current connection
        unset($this->pdo);

        // any PDOStatement instances will also hold a reference to the connection
        // so we need to destroy those as well...
        $this->statements = [];

        $this->pdo = new PDO(
            $this->dsn->pdo,
            $this->dsn->user,
            $this->dsn->pass,
        );

        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // post connection config - ensure utf8 everywhere
        switch ($this->dsn->type) {

            case static::TYPE_MYSQL:
                $this->pdo->exec("SET NAMES 'utf8mb4'");
                break;

            case static::TYPE_POSTGRES:
                $this->pdo->exec("SET NAMES 'utf8'");
                break;

            case static::TYPE_SQL_SERVER:
                $this->pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8);
                break;

        }

    }

    /**
     * Returns a copy of the connection details.
     *
     * @return stdClass Connection details (type, host, port, etc.)
     */
    public function getDSN(): stdClass {
        return clone $this->dsn;
    }

    /**
     * Prepares a SQL statement with caching.
     *
     * Caches prepared statements by their SHA1 hash for reuse.
     * Accepts either a string SQL statement or an existing PDOStatement.
     */
    public function prepare(string|PDOStatement $statement): PDOStatement {

        // it's PDOStatement so has already been prepared
        if (is_object($statement)) {
            return $statement;
        }

        $key = sha1($statement);

        if (empty($this->statements[$key])) {
            $start = microtime(true);
            $this->statements[$key] = $this->pdo->prepare($statement);
            $this->stats->prepare_time += microtime(true) - $start;
        }

        return $this->statements[$key];

    }

    /**
     * Executes a SQL query with parameter binding.
     *
     * Automatically prepares statements and tracks execution statistics.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     */
    public function query(string|PDOStatement $statement, array $params = []): PDOStatement {

        $statement = $this->prepare($statement);

        $this->bindParams($statement, $params);

        $start = microtime(true);
        $statement->execute();
        $this->stats->query_time += microtime(true) - $start;

        $this->stats->query_count++;

        return $statement;

    }

    /**
     * Executes a SQL statement and returns the number of affected rows.
     *
     * Useful for INSERT, UPDATE, or DELETE operations.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     */
    public function execute(string|PDOStatement $statement, array $params = []): int {
        return $this->query($statement, $params)->rowCount();
    }

    /**
     * Executes a SQL query and returns all result rows.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<int, array<string, mixed>> Result rows as associative arrays
     */
    public function getAll(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = $statement->fetchAll();

        return $result !== false ? $result : [];

    }

    /**
     * Executes a SQL query and returns multiple result sets.
     *
     * Useful for stored procedures that return multiple result sets.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<int, array<int, array<string, mixed>>> Array of result sets
     */
    public function getAllMulti(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        // get the first resultset
        $result = [
            $statement->fetchAll(),
        ];

        // now get all the remaining resultsets
        while ($statement->nextRowset()) {
            $result[] = $statement->fetchAll();
        }

        // convert falses to arrays for consistency
        foreach ($result as &$rowset) {
            if ($rowset === false) {
                $rowset = [];
            }
        }

        return $result;

    }

    /**
     * Executes a SQL query and returns an associative array using the first column as keys.
     *
     * If the result has only two columns, values will be the second column.
     * Otherwise, values will be arrays of the remaining columns.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<string|int, mixed> Associative array of results
     */
    public function getAssoc(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = [];

        while ($row = $statement->fetch()) {
            $key = array_shift($row);
            $result[$key] = count($row) == 1 ? array_shift($row) : $row;
        }

        return $result;

    }

    /**
     * Executes a SQL query and returns a nested associative array.
     *
     * Uses the first two columns as nested keys.
     * If the result has only three columns, values will be the third column.
     * Otherwise, values will be arrays of the remaining columns.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<string|int, array<string|int, mixed>> Nested associative array
     */
    public function getAssocMulti(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = [];

        while ($row = $statement->fetch()) {
            $k1 = array_shift($row);
            $k2 = array_shift($row);
            $v  = count($row) == 1 ? array_shift($row) : $row;
            if (empty($result[$k1])) {
                $result[$k1] = [];
            }
            $result[$k1][$k2] = $v;
        }

        return $result;

    }

    /**
     * Executes a SQL query and returns the first row.
     *
     * Returns an empty array if no rows are found.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<string, mixed> First row as associative array
     */
    public function getRow(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = $statement->fetch();

        return $result !== false ? $result : [];

    }

    /**
     * Executes a SQL query and returns the first column of all rows.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return array<int, mixed> Values from the first column
     */
    public function getCol(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = [];

        while ($row = $statement->fetch()) {
            $result[] = array_shift($row);
        }

        return $result;

    }

    /**
     * Executes a SQL query and returns a single scalar value.
     *
     * Returns the first column of the first row.
     * Returns null if no rows are found.
     *
     * @param array<string|int, mixed> $params Named or positional parameters
     * @return mixed Single scalar value or null
     */
    public function getOne(string|PDOStatement $statement, array $params = []): mixed {

        $statement = $this->query($statement, $params);

        $result = $statement->fetchColumn();

        return $result !== false ? $result : null;

    }

    /**
     * Execute a raw SQL string and return the number of affected rows.
     *
     * Primarily used for DDL queries. Do not use this with:
     * - Anything (data/parameters/etc) that comes from userland
     * - Select queries - the answer will always be 0 as no rows are affected.
     * - Everyday queries - use query() or execute()
     */
    public function rawExec(string $sql): int {

        $start = microtime(true);
        $result = $this->pdo->exec($sql);
        $this->stats->query_time += microtime(true) - $start;

        $this->stats->query_count++;

        // $result will be false if the query failed
        // but we have ERRMODE set to PDO::ERRMODE_EXCEPTION so it should never be false here
        return (int) $result;

    }

    /**
     * Begins a transaction.
     */
    public function begin(): bool {

        return $this->pdo->beginTransaction();

    }

    /**
     * Commits the current transaction.
     */
    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * Rolls back the current transaction.
     */
    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Checks if a transaction is currently active.
     */
    public function inTransaction(): bool {

        return $this->pdo->inTransaction();

    }

    /**
     * Gets the last insert ID from the database.
     *
     * For databases that support sequences, you can specify the sequence name.
     *
     * @throws RuntimeException If the last insert ID could not be retrieved
     */
    public function insertId(string $name = ''): string {

        $id = $this->pdo->lastInsertId($name);

        if ($id === false) {
            throw new RuntimeException("Failed to retrieve last insert ID");
        }

        return $id;

    }

    /**
     * Quotes a value for safe use in SQL statements.
     */
    public function quote(mixed $value, int $type = PDO::PARAM_STR): string {
        return $this->pdo->quote($value, $type);
    }

    /**
     * Quotes an identifier (table or column name) for the current database.
     *
     * Uses appropriate quoting style based on database type:
     * - MySQL/SQLite: `identifier`
     * - PostgreSQL: "identifier"
     * - SQL Server: [identifier]
     */
    public function quoteIdentifier(string $name): string {

        $name = trim($name);

        if ($name == '*') {
            return $name;
        }

        // ANSI-SQL says to use double quotes to quote identifiers
        $char = '"';

        // MySQL uses backticks cos it's special
        if ($this->dsn->type == static::TYPE_MYSQL) {
            $char = '`';
        }

        return $char . $name . $char;

    }

    /**
     * Gets the last error information from the database.
     *
     * @return array
     */
    public function getLastError(): array {
        return $this->pdo->errorInfo();
    }

    /**
     * Gets information about the database connection.
     *
     * @return array{dsn: stdClass, stats: stdClass, driver: string, client: string, server: string} Connection information
     */
    public function getInfo(): array {

        $server = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (!$this->dsn->type == static::TYPE_SQLITE) {
            $server .= ': ' . $this->pdo->getAttribute(PDO::ATTR_SERVER_INFO);
        }

        return [
            'dsn'    => clone $this->dsn,
            'stats'  => clone $this->stats,
            'driver' => $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'client' => $this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
            'server' => $server,
        ];

    }

    /**
     * Binds named and positional parameters to a PDOStatement.
     *
     * Handles both named (:param) and positional (?) parameters.
     * Automatically determines parameter types.
     *
     * @param array<string|int, mixed> $params Parameters to bind
     */
    protected function bindParams(PDOStatement $statement, array $params): void {

        foreach ($params as $name => $value) {

            $type = PDO::PARAM_STR;

            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            }

            // handle positional (?) and named (:name) parameters
            $name = is_numeric($name) ? (int) $name + 1 : ":{$name}";

            $statement->bindValue($name, $value, $type);

        }

    }

}
