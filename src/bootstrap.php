<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

use spl\SPL;
use spl\util\Env;
use spl\Random;

// determine if this is a command-line environment.
define('SPL_CLI', defined('STDIN') && is_resource(STDIN) && (get_resource_type(STDIN) == 'stream'));

// generate a unique request id
define('SPL_REQUEST_ID', Random::hex(8));

// time that the request started - or now if it's not set
define('SPL_START_TIME', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

if( !function_exists('d') ) {
    function d( ...$vars ) {
        if( !SPL_DEBUG ) {
            return;
        }
        foreach( $vars as $var ) {
            SPL::dump($var);
        }
    }
}

if( !function_exists('dd') ) {
    function dd( ...$vars ) {
        if( !SPL_DEBUG ) {
            return;
        }
        if( !SPL_CLI ) {
            headers_sent() || header('Content-type: text/plain; charset=UTF-8');
        }
        foreach( $vars as $var ) {
            SPL::dump($var);
        }
        exit(2);
    }
}

if( !function_exists('env') ) {
    function env( string $var, mixed $default = null ): mixed {

        return Env::get($var, $default);

    }
}
