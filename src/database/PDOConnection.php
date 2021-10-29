<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database;

use Closure, PDO, PDOStatement, PDOException;

use spl\contracts\database\{DatabaseConnection, Query};
// use spf\contracts\profiler\{ProfilerAware, ProfilerAwareTrait};

use spl\database\query\{Select, Insert, Update, Delete};
use spl\database\exceptions\{DatabaseException, QueryException, TransactionException};

/**
 * A wrapper for PDO that provides some handy extra functions and streamlines everything else.
 */
class PDOConnection implements DatabaseConnection {
// abstract class PdoConnection implements DatabaseConnection, ProfilerAware, Dumpable {

    /**
     * Prepared statement cache.
     */
    protected array $statements = [];

    /**
     * Create a new database connection.
     */
    public function __construct(
        protected PDO $pdo,
        protected ?DSN $dsn = null,
    ) {

        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);           // always use exceptions

        if( isset($dsn) ) {
            $this->setCharacterSet(
                $dsn->getOption('charset', 'UTF8'),
                $dsn->getOption('collation')
            );
        }

    }

    public function select( ...$columns ): Query {
        return (new Select($this))->cols(...$columns);
    }

    public function insert(): Query {
        return new Insert($this);
    }

    public function update( string $table ): Query {
        return (new Update($this))->table($table);
    }

    public function delete(): Query {
        return new Delete($this);
    }

    public function getPDO(): PDO {
        return $this->$pdo;
    }

    public function getDSN(): DSN {
        return $this->$dsn;
    }

    public function prepare( string|PDOStatement $statement ): PDOStatement {

        if( is_object($statement) ) {
            return $statement;
        }

        $key = sha1($statement);

        if( empty($this->statements[$key]) ) {
            $this->statements[$key] = $this->pdo->prepare($statement);
        }

        return $this->statements[$key];

    }

    public function query( string|PDOStatement $statement, array $params = [] ): PDOStatement {

        try {

            $statement = $this->prepare($statement);

            $this->bindParams($statement, $params);

            $start = microtime(true);
            $statement->execute();
            $duration = microtime(true) - $start;

        }
        catch( PDOException $e ) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }

        return $statement;

    }

    public function execute( string|PDOStatement $statement, array $params = [] ): int {

        return $this->query($statement, $params)->rowCount();

    }

    public function getAll( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = $statement->fetchAll();
                if( $result === false ) {
                    $result = [];
                }
                return $result;
            }
        );
    }

    public function getAllMulti( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {

                // get the first resultset
                $result = [
                    $statement->fetchAll()
                ];

                // now get all the remaining resultsets
                while( $statement->nextRowset() ) {
                    $result[] = $statement->fetchAll();
                }

                // convert falses to arrays for consistency
                foreach( $result as &$rowset ) {
                    if( $rowset === false ) {
                        $rowset = [];
                    }
                }

                return $result;
            }
        );
    }

    public function getAssoc( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = [];
                while( $row = $statement->fetch() ) {
                    $key = array_shift($row);
                    $result[$key] = count($row) == 1 ? array_shift($row) : $row;
                }
                return $result;
            }
        );
    }

    public function getAssocMulti( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = [];
                while( $row = $statement->fetch() ) {
                    $k1 = array_shift($row);
                    $k2 = array_shift($row);
                    $v  = count($row) == 1 ? array_shift($row) : $row;
                    if( empty($result[$k1]) ) {
                        $result[$k1] = [];
                    }
                    $result[$k1][$k2] = $v;
                }
                return $result;
            }
        );
    }

    public function getRow( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = $statement->fetch();
                if( $result === false ) {
                    $result = [];
                }
                return $result;
            }
        );
    }

    public function getCol( string|PDOStatement $statement, array $params = [] ): array {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = [];
                while( $row = $statement->fetch() ) {
                    $result[] = array_shift($row);
                }
                return $result;
            }
        );
    }

    public function getOne( string|PDOStatement $statement, array $params = [] ) {
        return $this->getResult(
            $statement,
            $params,
            function( PDOStatement $statement ) {
                $result = $statement->fetchColumn();
                if( $result === false ) {
                    $result = null;
                }
                return $result;
            }
        );
    }

    /**
     * Execute a raw SQL string and return the number of affected rows.
     * Primarily used for DDL queries. Do not use this with:
     * - Anything (data/parameters/etc) that comes from userland
     * - Select queries - the answer will always be 0 as no rows are affected.
     * - Everyday queries - use query() or execute()
     */
    public function rawExec( string $sql ): int {

        try {
            return $this->pdo->exec($sql);
        }
        catch( PDOException $e ) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }

    }

    public function begin(): bool {

        return $this->transactionMethod('beginTransaction');

    }

    public function commit(): bool {

        return $this->transactionMethod('commit');

    }

    public function rollback(): bool {

        return $this->transactionMethod('rollBack');

    }

    public function inTransaction(): bool {

        return $this->pdo->inTransaction();

    }

    public function insertId( string $name = '' ): string {

        return $this->pdo->lastInsertId($name);

    }

    public function quote( $value, int $type = PDO::PARAM_STR ): string {

        return $this->pdo->quote($value, $type);

    }

    public function quoteIdentifier( string $name ): string {

        $name = trim($name);

        if( $name == '*' ) {
            return $name;
        }

        // ANSI-SQL says to use double quotes to quote identifiers
        $char = '"';

        // MySQL uses backticks cos it's special
        if( $this->dsn?->isMySQL() ) {
            $char = '`';
        }

        return $char. $name. $char;

    }

    public function getLastError(): array {

        return $this->pdo->errorInfo();

    }

    public function getInfo(): array {
        try {
            return [
                'dsn'       => (string) ($this->dsn ?? ''),
                'driver'    => $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'client'    => $this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'server'    => $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION). ': '. $this->pdo->getAttribute(PDO::ATTR_SERVER_INFO)
            ];
        }
        catch( PDOException $e ) {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

    }

    /**
     * Bind named and positional parameters to a PDOStatement.
     */
    protected function bindParams( PDOStatement $statement, array $params ): void {

        foreach( $params as $name => $value ) {

            $type = PDO::PARAM_STR;

            if( is_int($value) ) {
                $type = PDO::PARAM_INT;
            }

            // handle positional (?) and named (:name) parameters
            $name = is_numeric($name) ? (int) $name + 1 : ":{$name}";

            $statement->bindValue($name, $value, $type);

        }

    }

    /**
     * Perform a select query and use a callback to extract a result.
     * @param  PDOStatement|string $statement   an existing PDOStatement object or a SQL string.
     * @param  array $params        an array of parameters to pass into the query.
     * @param  \Closure $callback   function to yield a result from the executed statement
     * @return array
     */
    protected function getResult( $statement, $params, Closure $callback ) {

        $statement = $this->query($statement, $params);

        return $callback($statement);

    }

    /**
     * Make sure the connection is using the correct character set
     *
     * @param string $charset   the character set to use for the connection
     * @param string $collation the collation method to use for the connection
     * @return self
     */
    protected function setCharacterSet( string $charset, string $collation = '' ): static {

        // not supported for sqlsrv
        if( $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlsrv' ) {
            return $this;
        }

        if( empty($charset) ) {
            throw new DatabaseException('No character set specified');
        }

        $sql = 'SET NAMES '. $this->pdo->quote($charset);

        if( $collation ) {
            $sql .= ' COLLATE '. $this->pdo->quote($collation);
        }

        $this->pdo->exec($sql);

        return $this;

    }

    protected function transactionMethod( string $method ): bool {

        try {
            return $this->pdo->$method();
        }
        catch( PDOException $e ) {
            throw new TransactionException($e->getMessage(), $e->getCode(), $e);
        }

    }

}
