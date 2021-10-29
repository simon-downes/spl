<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\support;

use ArrayAccess;
use LogicException;

class Config implements ArrayAccess {

    protected array $cache = [];

    public function __construct( protected array $data = [] ) {
    }

    public function has( int|string $key ): bool {
        return $this->get($key, null) !== null;
    }

    public function get( int|string $key, mixed $default = null ): mixed {

        // shortcut lookups for data and cache entries with a
        if( isset($this->data[$key]) ) {
            return $this->data[$key];
        }
        elseif( isset($this->cache[$key]) ) {
            return $this->cache[$key];
        }

        $parts   = explode('.', $key);
        $context = $this->data;

        foreach( $parts as $part ) {
            if( !isset($context[$part]) ) {
                return $default;
            }
            $context = $context[$part];
        }

        // we create a cache of scaler values that are more than one level deep
        if( (count($parts) >= 2) && is_scalar($context)  ) {
            $this->cache[$key] = $context;
        }

        return $context;

    }

    public function offsetExists( $offset ): bool {
        return $this->has($offset);
    }

    public function offsetGet( $offset ): mixed {
        return $this->get($offset);
    }

    public function offsetSet( $offset, $value ): void {
        throw new LogicException(static::class. ' is immutable');
    }

    public function offsetUnset( $offset ): void {
        throw new LogicException(static::class. ' is immutable');
    }

}
