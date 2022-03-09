<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\providers;

use Illuminate\Support\ServiceProvider;

use spl\database\ConnectionManager;

/**
 * This is a Laravel/Lumen service provider that will create a ConnectionManager instance and register all database
 * connections configured with a dsn config item.
 */
class DatabaseServiceProvider extends ServiceProvider {

    public function register(): void {

        // define a ConnectionManager instance and register all the databases that have a dsn configured
        $this->app->singleton(ConnectionManager::class, function( $app ) {

            $dbm = new ConnectionManager();

            foreach( config('database.connections') as $name => $db ) {
                if( !empty($db['dsn']) ) {
                    $dbm->add($name, $db['dsn']);
                }
            }

            return $dbm;

        });

        // also create an alias in the form db.<name> for each database connection that has a dsn configured
        foreach( config('database.connections') as $name => $db ) {
            if( !empty($db['dsn']) ) {
                $this->app->singleton("db.{$name}", function( $app ) use ( $name ) {
                    return $app->make(ConnectionManager::class)->get($name);
                });
            }
        }

    }

}
