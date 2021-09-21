<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl;

use Exception, ErrorException, Throwable, BadMethodCallException, ReflectionClass, ReflectionMethod;

use spl\debug\VarDumper;

class SPL {

    /**
     * The debug mode flag.
     */
    protected static bool $debug = false;

    /**
     * Array of helper methods accessable as static method on this class.
     */
    protected static array $helpers = [];

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Determines if this is a command-line environment.
     */
    public static function isCLI(): bool {
        return defined('STDIN') && is_resource(STDIN) && (get_resource_type(STDIN) == 'stream');
    }

    /**
     * Determines if debug mode is enabled.
     */
    public static function isDebug(): bool {
        return static::$debug;
    }

    /**
     * Enables or disabled debug mode.
     */
    public static function setDebug( bool $debug = false ): void {
        static::$debug = (bool) $debug;
    }

    /**
     * Pretty-print a variable to STDOUT.
     */
    public static function dump( $var ): void {
        echo (new VarDumper())->dump($var), "\n";
    }

    public static function init(): void {

        // global helper functions (e.g. d() and dd())
        require __DIR__.'/bootstrap.php';

        static::registerHelpers([
            'spl\\helpers\\ArrayHelper',
            'spl\\helpers\\StringHelper',
            'spl\\helpers\\DateTimeHelper',
            'spl\\helpers\\Inflector',
        ]);

    }

    /**
     * Executes the specified closure, wrapping it in SPF's error and exception handling.
     * @return mixed
     */
    public static function run( callable $callable, ...$args ): mixed {

        try {

            // use our error handler - we want to elevate all errors to exceptions
            $error_handler = set_error_handler(function( int $severity, string $message, string $file, int $line ): bool {
                if( error_reporting() & $severity ) {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                }
                return false;
            });

            $result = call_user_func_array($callable, $args);

            // restore the original error handler
            set_error_handler($error_handler);

        }
        catch( Throwable $e ) {
            static::error($e);
        }

        return $result;

    }

    public static function error( Throwable $error ): void {

        $debug = static::isDebug();

        // cli scripts always just get a pretty print dump of the error
        if( static::isCLI() ) {
            static::dump($error);
        }
        else {
            require __DIR__. '/error.php';
        }

        die();

    }

    /**
     * Register one or multiple static helper classes.
     * Public static methods defined within each class will become statically callable via the SPF class.
     * .e.g spf\helpers\ArrayHelper::sum() -> spf\SPF::sum()
     */
    public static function registerHelpers( array $classes ): void {

        foreach( $classes as $class ) {

            $class   = new ReflectionClass($class);
            $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

            foreach( $methods as $m ) {
                static::addHelperMethod($class->name, $m->name);
            }

        }

    }

    /**
     * Register a single static method as a helper.
     */
    public static function addHelperMethod( string $class, string $method ): void {

        $k = strtolower($method);

        if( method_exists(__CLASS__, $method) ) {
            throw new Exception(sprintf("Helper methods cannot override pre-defined SPF methods - '%s' is reserved", $method));
        }
        elseif( isset(static::$helpers[$k]) && static::$helpers[$k][0] != $class ) {
            throw new Exception(sprintf("Helper method '%s' already defined in class '%s', duplicate in '%s'", $method, static::$helpers[$k][0], $class));
        }

        static::$helpers[$k] = [$class, $method];

    }

    public static function __callStatic( $method, array $args = [] ) {

        $k = strtolower($method);

        if( empty(static::$helpers[$k]) ) {
            throw new BadMethodCallException("Unknown helper method '$method'");
        }

        return call_user_func_array(static::$helpers[$k], $args);

    }

}
