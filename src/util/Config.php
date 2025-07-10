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
 * Configuration container with dot notation support.
 *
 * Provides access to nested configuration values using dot notation:
 * - $config->get('database.host')
 * - $config['database.port']
 *
 * Supports array access interface and value caching.
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
     * Loads configuration from a PHP file that returns an array.
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
     * Loads configuration from a file if it exists.
     *
     * Returns an empty Config instance if the file doesn't exist or isn't readable.
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
     * Creates a new Config instance with optional initial data.
     */
    public function __construct(protected array $data = []) {}

    /**
     * Checks if a configuration key exists and has a non-null value.
     */
    public function has(int|string $key): bool {
        return $this->get($key, null) !== null;
    }

    /**
     * Returns all configuration data as an array.
     */
    public function all(): array {
        return $this->data;
    }

    /**
     * Gets a configuration value using dot notation for nested values.
     *
     * Uses a cache for frequently accessed values to improve performance.
     * Returns default value if the key doesn't exist.
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
     * Implements ArrayAccess::offsetExists
     */
    public function offsetExists($offset): bool {
        return $this->has($offset);
    }

    /**
     * Implements ArrayAccess::offsetGet
     */
    public function offsetGet($offset): mixed {
        return $this->get($offset);
    }

    /**
     * Implements ArrayAccess::offsetSet
     *
     * @throws LogicException Always thrown as Config is immutable
     */
    public function offsetSet($offset, $value): void {
        throw new LogicException(static::class . ' is immutable');
    }

    /**
     * Implements ArrayAccess::offsetUnset
     *
     * @throws LogicException Always thrown as Config is immutable
     */
    public function offsetUnset($offset): void {
        throw new LogicException(static::class . ' is immutable');
    }

}
