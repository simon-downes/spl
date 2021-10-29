<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database;

use InvalidArgumentException;

use Countable;

use PDO, PDOException;

use spl\contracts\database\DatabaseConnection;
use spl\database\exceptions\{DatabaseException, ConfigurationException, ConnectionException};

class ConnectionManager implements Countable {

    /**
     * Array of database connections.
     */
    protected array $connections = [];

    /**
     * Name of the default connection.
     */
    protected string $default = '';

    public static function connect( array|string|DSN $dsn ) {

        if( !($dsn instanceof DSN) ) {
            $dsn = new DSN($dsn);
        }

        try {
            return new PDOConnection(
                new PDO(
                    $dsn->pdo,
                    $dsn->user,
                    $dsn->pass,
                    $dsn->options
                ),
                $dsn
            );
        }
        catch( PDOException $e ) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }

    }

    public function count(): int {
        return count($this->connections);
    }

    public function add( string $name, DatabaseConnection $connection ): void {

        $this->checkName($name);

        $this->connections[$name] = $connection;

        // no default connection so use the first one
        if( empty($this->default) ) {
            $this->default = $name;
        }

    }

    public function remove( string $name ): void {

        unset($this->connections[$name]);

    }

    public function get( string $name = '' ): ?DatabaseConnection {

        if( empty($name) ) {
            $name = $this->default;
        }

        return $this->connections[$name] ?? null;

    }

    public function has( string $name ): bool {

        return isset($this->connections[$name]);

    }

    public function setDefault( string $name ): void {

        if( empty($this->connections[$name]) ) {
            throw new InvalidArgumentException("Unknown Connection: {$name}");
        }

        $this->default = $name;

    }

    /**
     * Ensure we have a valid connection name, i.e. it's not empty and doesn't already exist.
     */
    protected function checkName( string $name ): void {
        if( empty($name) ) {
            throw new DatabaseException('Managed database connections must have a name');
        }
        if( $this->has($name) ) {
            throw new DatabaseException("Connection already exists with name: {$name}");
        }
    }

}
