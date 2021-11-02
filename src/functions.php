<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

use spl\SPL;

if( !function_exists('d') ) {
    function d( ...$vars ) {
        if( !SPL::isDebug() ) {
            return;
        }
        foreach( $vars as $var ) {
            SPL::dump($var);
        }
    }
}

if( !function_exists('dd') ) {
    function dd( ...$vars ) {
        if( !SPL::isDebug() ) {
            return;
        }
        if( !SPL::isCLI() ) {
            headers_sent() || header('Content-type: text/plain; charset=UTF-8');
        }
        foreach( $vars as $var ) {
            SPL::dump($var);
        }
        die();
    }
}

if( !function_exists('env') ) {

    function env( string $k, mixed $default = null ): mixed {

        $v = getenv($k);

        if( $v === false ) {
            return $default;
        }

        // convert certain strings to their typed values
        $v = match( $v ) {
            'true'   => true,
            'false'  => false,
            'null'   => null,
            default  => $v
        };

        return $v;

    }
}
