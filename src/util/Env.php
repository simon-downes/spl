<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use RuntimeException;

/**
 * Simple environment helper that can load env files and return environment variables.
 *
 * Provides methods for loading .env files and accessing environment variables with type conversion.
 */
class Env {

    /**
     * Cannot be instantiated.
     */
    private function __construct() {}

    /**
     * Returns all environment variables.
     */
    public static function all(): array {
        return $_ENV + getenv();
    }

    /**
     * Gets an environment variable with automatic type conversion.
     *
     * Converts 'true', 'false', and 'null' strings to their respective types.
     * Other values are returned as-is; quotes in env files are removed when the file is loaded.
     */
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

        return $v;

    }

    /**
     * Read a raw value from the current environment.
     *
     * This function will first look in $_ENV as that where .env file variables are loaded here
     * - in addition to being populated if "e" is in the variables_order ini setting
     * If no match is found then getenv() is used.
     * If the variable cannot be found then the function returns false.
     *
     * @return mixed The value of the environment variable or false if not found
     */
    protected static function getRaw(string $var): mixed {

        return $_ENV[$var] ?? getenv($var);

    }

    /**
     * Load environment variables from the specified file with an error if it doesn't exist.
     *
     * @throws RuntimeException If the file cannot be read
     */
    public static function load(string $file): void {

        if (!is_readable($file)) {
            throw new RuntimeException("Cannot read environment file: {$file}");
        }

        static::safeLoad($file);

    }

    /**
     * Load environment variables from the specified file if it exists.
     *
     * Each line is `KEY=value`, optionally prefixed with `export `. Blank lines, lines starting
     * with `#` and lines without a `=` or a key are ignored. Variables that are already set in
     * the environment are not overwritten. See parseValue() for the value syntax.
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

            $line = trim($line);

            // blank or comment so ignore it
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // optional shell-style export prefix
            $line = (string) preg_replace('/^export\s+/', '', $line);

            // not an assignment so ignore it
            if (!str_contains($line, '=')) {
                continue;
            }

            // split on first appearance of '='
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);

            if ($k === '') {
                continue;
            }

            # parse the value and add it to the environment if it's not been defined already
            if (!isset($current[$k])) {
                $_ENV[$k] = static::parseValue($v);
            }

        }

    }

    /**
     * Parse a value string from an env file.
     *
     * - A value wrapped in matching single or double quotes has the quotes removed and keeps
     *   everything inside them, including `#`. Text after the closing quote may only be a comment.
     * - In any other value a `#` preceded by whitespace starts a comment; the value is then trimmed.
     * - `${VAR}` references are replaced with the variable's current value, except in single-quoted values.
     *
     * @throws RuntimeException If an embedded variable is undefined
     */
    protected static function parseValue(string $value): string {

        if (preg_match('/^([\'"])(.*?)\1\s*(?:#.*)?$/', trim($value), $matches)) {

            // single-quoted values are literal
            if ($matches[1] === "'") {
                return $matches[2];
            }

            return static::interpolate($matches[2]);

        }

        // unquoted (or not properly quoted) so strip any inline comment
        $value = trim((string) preg_replace('/\s#.*$/', '', $value));

        return static::interpolate($value);

    }

    /**
     * Replace `${VAR}` references with values from the current environment.
     *
     * @throws RuntimeException If an embedded variable is undefined
     */
    protected static function interpolate(string $value): string {

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

            $value = str_replace($var, (string) $v, $value);
        }

        return $value;

    }

}
