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
