<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use RuntimeException;

/**
 * Simple environment helper that can load env files and return environment variables.
 */
class Env {

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    public static function all(): array {
        return $_ENV + getenv();
    }

    public static function get(string $var, mixed $default = null): mixed {

        $v = static::getRaw($var);

        if ($v === false) {
            return $default;
        }

        // convert certain strings to their typed values
        switch (strtolower($v)) {
            case 'true':
                return true;

            case 'false':
                return false;

            case 'null':
                return null;
        }

        if (preg_match('/^([\'"])(.*)\1$/', (string) $v, $matches)) {
            return $matches[2];
        }

        return $v;

    }

    /**
     * Read a value from the current environment.
     * This function will first look in $_ENV as that where .env file variables are loaded here
     * - in addition to being populated if "e" is in the variables_order ini setting
     * If no match is found then getenv() is used.
     * If the variable cannot be found then the function returns false.
     */
    protected static function getRaw(string $var): mixed {

        return $_ENV[$var] ?? getenv($var);

    }

    /**
     * Load environment variables from the specified file with an error if it doesn't exist.
     */
    public static function load(string $file): void {

        if (!is_readable($file)) {
            throw new RuntimeException("Cannot read environment file: {$file}");
        }

        static::safeLoad($file);

    }

    /**
     * Load environment variables from the specified file if it exists.
     */
    public static function safeLoad(string $file): void {

        # can't read the file so don't do anything
        if (!is_readable($file)) {
            return;
        }

        # the current environment - so we can make sure we don't overwrite existing values
        $current = $_ENV + getenv();

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // if the file could not be read then return -  shouldn't get here as we check for readability above
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {

            // comment so ignore it
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // split on first appearance of '=' and trim white space from both elements
            list($k, $v) = array_map('trim', explode('=', $line, 2));

            # parse the value and add it to the environment if it's not been defined already
            if (!isset($current[$k])) {
                $_ENV[$k] = static::parseValue($v);
            }

        }

    }

    /**
     * Given a value string from an env file, parse it to resolve any embedded variables and remove surrounding quotes.
     * TODO: remove end of line comments?
     */
    protected static function parseValue(string $value): string {

        preg_match_all('/\${.*?}/', $value, $matches);

        foreach ($matches[0] as $var) {

            // remove the ${ } construct
            $var_name = substr($var, 2, -1);

            // lookup the value from current environment
            $v = static::getRaw($var_name);

            // invalid variable so throw an exception
            if ($v === false) {
                throw new RuntimeException("Undefined environment variable: '{$var_name}'");
            }

            // echo substr($var, 2, -1). "\n";
            $value = str_replace($var, $v, $value);
        }

        return $value;

    }

}
