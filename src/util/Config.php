<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use ArrayAccess;
use LogicException;
use RuntimeException;

/**
 * Configuration container class.
 * 
 * Provides access to configuration values with dot notation support.
 * 
 * @implements ArrayAccess<string|int, mixed>
 */
class Config implements ArrayAccess {

    /**
     * Cache for frequently accessed values.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * Load configuration from a file.
     *
     * @param string $file The path to the configuration file
     * 
     * @return static A new Config instance
     * 
     * @throws RuntimeException If the file cannot be read
     */
    public static function load(string $file): static {

        if (!is_readable($file)) {
            throw new RuntimeException("Cannot read config file: {$file}");
        }

        return static::safeLoad($file);

    }

    /**
     * Load configuration from a file if it exists.
     * 
     * Returns an empty Config instance if the file doesn't exist.
     *
     * @param string $file The path to the configuration file
     * 
     * @return static A new Config instance
     */
    public static function safeLoad(string $file): static {

        # can't read the file so return empty config
        if (!is_readable($file)) {
            return new static();
        }

        // config file should be a PHP file that returns an array
        return new static(
            require($file),
        );

    }

    /**
     * Create a new Config instance.
     *
     * @param array $data The configuration data
     */
    public function __construct(protected array $data = []) {}

    /**
     * Check if a configuration key exists.
     *
     * @param int|string $key The configuration key
     * 
     * @return bool True if the key exists
     */
    public function has(int|string $key): bool {
        return $this->get($key, null) !== null;
    }

    /**
     * Get all configuration data.
     *
     * @return array All configuration data
     */
    public function all(): array {
        return $this->data;
    }

    /**
     * Get a configuration value.
     * 
     * Supports dot notation for accessing nested values.
     *
     * @param int|string $key     The configuration key
     * @param mixed      $default The default value if the key doesn't exist
     * 
     * @return mixed The configuration value or the default
     */
    public function get(int|string $key, mixed $default = null): mixed {

        // shortcut lookups for data and cache entries with a
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }
        elseif (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $parts   = explode('.', (string) $key);
        $context = $this->data;

        foreach ($parts as $part) {
            if (!isset($context[$part])) {
                return $default;
            }
            $context = $context[$part];
        }

        // we create a cache of scaler values that are more than one level deep
        if ((count($parts) >= 2) && is_scalar($context)) {
            $this->cache[$key] = $context;
        }

        return $context;

    }

    /**
     * Check if an offset exists.
     *
     * @param mixed $offset The offset to check
     * 
     * @return bool True if the offset exists
     */
    public function offsetExists($offset): bool {
        return $this->has($offset);
    }

    /**
     * Get the value at the specified offset.
     *
     * @param mixed $offset The offset to get
     * 
     * @return mixed The value at the offset
     */
    public function offsetGet($offset): mixed {
        return $this->get($offset);
    }

    /**
     * Set the value at the specified offset.
     * 
     * This operation is not supported as Config is immutable.
     *
     * @param mixed $offset The offset to set
     * @param mixed $value  The value to set
     * 
     * @return void
     * 
     * @throws LogicException Always thrown as Config is immutable
     */
    public function offsetSet($offset, $value): void {
        throw new LogicException(static::class . ' is immutable');
    }

    /**
     * Unset the value at the specified offset.
     * 
     * This operation is not supported as Config is immutable.
     *
     * @param mixed $offset The offset to unset
     * 
     * @return void
     * 
     * @throws LogicException Always thrown as Config is immutable
     */
    public function offsetUnset($offset): void {
        throw new LogicException(static::class . ' is immutable');
    }

}
