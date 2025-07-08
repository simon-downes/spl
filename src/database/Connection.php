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
 * A wrapper for PDO that provides some handy extra functions and streamlines everything else.
 * 
 * Provides simplified database access with support for multiple database types.
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
     * Create a new database connection.
     *
     * @param string $url The database connection URL
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
     * Re-establish the database connection.
     * 
     * Mostly used after calling pcntl_fork() or similar.
     * This method will have no effect if references to the underlying PDO instance exist outside of this PDOConnection instance.
     *
     * @return void
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
     * Get the DSN object.
     *
     * @return stdClass A clone of the DSN object
     */
    public function getDSN(): stdClass {
        return clone $this->dsn;
    }

    /**
     * Prepare a SQL statement.
     *
     * @param string|PDOStatement $statement The SQL statement to prepare
     * 
     * @return PDOStatement The prepared statement
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
     * Execute a SQL query.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return PDOStatement The executed statement
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
     * Execute a SQL statement and return the number of affected rows.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return int The number of affected rows
     */
    public function execute(string|PDOStatement $statement, array $params = []): int {

        return $this->query($statement, $params)->rowCount();

    }

    /**
     * Execute a SQL query and return all rows.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array The result rows
     */
    public function getAll(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = $statement->fetchAll();

        return $result !== false ? $result : [];

    }

    /**
     * Execute a SQL query with multiple result sets and return all rows.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array An array of result sets
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
     * Execute a SQL query and return an associative array.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array The result as an associative array
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
     * Execute a SQL query and return a multi-dimensional associative array.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array The result as a multi-dimensional associative array
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
     * Execute a SQL query and return a single row.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array The first row of the result
     */
    public function getRow(string|PDOStatement $statement, array $params = []): array {

        $statement = $this->query($statement, $params);

        $result = $statement->fetch();

        return $result !== false ? $result : [];

    }

    /**
     * Execute a SQL query and return a single column.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return array The first column of the result
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
     * Execute a SQL query and return a single value.
     *
     * @param string|PDOStatement $statement The SQL statement to execute
     * @param array               $params    The parameters to bind to the query
     * 
     * @return mixed The first value of the first row of the result
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
     *
     * @param string $sql The SQL to execute
     * 
     * @return int The number of affected rows
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
     * Begin a transaction.
     *
     * @return bool True on success
     */
    public function begin(): bool {

        return $this->pdo->beginTransaction();

    }

    /**
     * Commit a transaction.
     *
     * @return bool True on success
     */
    public function commit(): bool {

        return $this->pdo->commit();

    }

    /**
     * Rollback a transaction.
     *
     * @return bool True on success
     */
    public function rollback(): bool {

        return $this->pdo->rollBack();

    }

    /**
     * Check if a transaction is active.
     *
     * @return bool True if a transaction is active
     */
    public function inTransaction(): bool {

        return $this->pdo->inTransaction();

    }

    /**
     * Get the last insert ID.
     *
     * @param string $name The name of the sequence object (if any)
     * 
     * @return string The last insert ID
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
     * Quote a value for use in a SQL statement.
     *
     * @param mixed $value The value to quote
     * @param int   $type  The parameter type (PDO::PARAM_*)
     * 
     * @return string The quoted value
     */
    public function quote(mixed $value, int $type = PDO::PARAM_STR): string {

        return $this->pdo->quote($value, $type);

    }

    /**
     * Quote an identifier (table or column name) for use in a SQL statement.
     *
     * @param string $name The identifier to quote
     * 
     * @return string The quoted identifier
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
     * Get the last error information.
     *
     * @return array The error information
     */
    public function getLastError(): array {

        return $this->pdo->errorInfo();

    }

    /**
     * Get information about the database connection.
     *
     * @return array Connection information
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
     * Bind named and positional parameters to a PDOStatement.
     *
     * @param PDOStatement $statement The statement to bind parameters to
     * @param array        $params    The parameters to bind
     * 
     * @return void
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
