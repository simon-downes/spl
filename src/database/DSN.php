<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database;

use spl\database\exceptions\ConfigurationException;

use spl\helpers\StringHelper;

/**
 * Describes database connection details.
 *
 * @property-read string $type type of database being connected to
 * @property-read string $host hostname or ip address of database server
 * @property-read string $port network port to connect on
 * @property-read string $user user name used for authentication
 * @property-read string $pass password used for authentication
 * @property-read string $db   name of the database schema to use
 * @property-read array  $options array of database specific options
 */
class DSN {

    const TYPE_MYSQL  = 'mysql';
    const TYPE_PGSQL  = 'pgsql';
    const TYPE_SQLITE = 'sqlite';
    const TYPE_SQLSRV = 'sqlsrv';

    protected $config;

    /**
     * Create a DSN from an array of parameters.
     * type - type of database (mysql, pgsql, sqlite, sqlsrv) required
     * host - hostname of database server
     * port - network port to connect on
     * user - user to connect as
     * pass - user's password
     * db - name of the database schema to connect to
     * options - an array of database specific options
     */
    public function __construct( array|string $config ) {

        // convert a DSN string to an array
        if( is_string($config) ) {

            $parts = StringHelper::parseURL($config);

            // no point continuing if it went wrong
            if( empty($parts) ) {
                throw new ConfigurationException("Invalid DSN string: {$config}");
            }

            $config = [
                'type'    => $parts['scheme'],
                'host'    => $parts['host'],
                'port'    => $parts['port'],
                'user'    => $parts['user'],
                'pass'    => $parts['pass'],
                'db'      => trim($parts['path'], '/'),
                'options' => $parts['options'],
            ];
        }

        // ensure we have the various elements in the array that we need
        $config = $config + array(
            'type'    => '',
            'host'    => 'localhost',
            'port'    => '',
            'user'    => '',
            'pass'    => '',
            'db'      => '',
            'options' => [],
        );

        if( empty($config['type']) ) {
            throw new ConfigurationException('No database type specified');
        }

        if( empty($config['db']) ) {
            throw new ConfigurationException('No database schema specified');
        }

        $this->configure($config);

    }

    public function isMySQL(): bool {
        return $this->config['type'] == static::TYPE_MYSQL;
    }

    public function isPgSQL(): bool {
        return $this->config['type'] == static::TYPE_PGSQL;
    }

    public function isSQLite(): bool {
        return $this->config['type'] == static::TYPE_SQLITE;
    }

    public function isSQLSrv(): bool {
        return $this->config['type'] == static::TYPE_SQLSRV;
    }

    /**
     * Dynamic property access.
     */
    public function __get( string $key ): mixed {
        return $this->config[$key] ?? null;
    }

    /**
     * Dynamic property access.
     */
    public function __isset( string $key ): bool {
        return isset($this->config[$key]);
    }

    public function getOption( string $name, float|int|string $default = '' ): float|int|string {
        return $this->options[$name] ?? $default;
    }

    /**
     * Convert the DSN into a URI-type string.
     */
    public function __toString(): string {

        return StringHelper::buildURL($this->config + [
            'scheme' => $this->config['type'],
            'path'   => '/'. $this->config['db'],
        ]);

    }

    /**
     * Ensure the dsn configuration is valid.
     */
    protected function configure( array $config ): void {

        $methods = [
            static::TYPE_MYSQL  => 'configureMySQL',
            static::TYPE_PGSQL  => 'configurePgSQL',
            static::TYPE_SQLITE => 'configureSQLite',
            static::TYPE_SQLSRV => 'configureSQLSrv',
        ];

        if( empty($methods[$config['type']]) ) {
            throw new ConfigurationException("Invalid database type: {$config['type']}");
        }

        $method = $methods[$config['type']];
        $this->config = $this->$method($config);

    }

    /**
     * Configure a MySQL DSN.
     */
    protected function configureMySQL( array $config ): array {

        if( empty($config['port']) ) {
            $config['port'] = 3306;
        }

        // construct a MySQL PDO connection string
        $config['pdo'] = sprintf(
            "mysql:host=%s;port=%s;dbname=%s",
            $config['host'],
            $config['port'],
            $config['db']
        );

        return $config;

    }

    /**
     * Configure a PostgreSQL DSN.
     */
    protected function configurePgSQL( array $config ): array {

        if( empty($config['port']) ) {
            $config['port'] = 5432;
        }

        // construct a PgSQL PDO connection string
        $config['pdo'] = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $config['host'],
            $config['port'],
            $config['db']
        );

        return $config;

    }

    /**
     * Configure a SQLite DSN.
     */
    protected function configureSQLite( array $config ): array {

        // these should always be null as they're invalid for SQLite connections
        $config['host'] = 'localhost';
        $config['port'] = null;
        $config['user'] = null;
        $config['pass'] = null;

        // construct a SQLite PDO connection string
        $config['pdo'] = sprintf(
            'sqlite:%s',
            (defined('APP_ROOT') ? APP_ROOT. '/' : ''). $config['db']
        );

        return $config;

    }

    /**
     * Configure a Microsoft SQL Server DSN.
     */
    protected function configureSQLSrv( array $config ): array {

        if (empty($config['port'])) {
            $config['port'] = 1433;
        }

        $config['pdo'] = sprintf('sqlsrv:Server=%s,%s;Database=%s', $config['host'], $config['port'], $config['db']);

        return $config;

    }

}
