<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\model;

use RuntimeException;
use ReflectionClass, ReflectionProperty;

use spl\contracts\model\Model;

abstract class SimpleModel implements Model {

    public function __construct( array $data ) {

        // get a list of valid properties and where the data array has a key with the same name
        // then assign the value of the element to the property
        foreach( $this->getPropertyList() as $property ) {
            if( isset($data[$property]) ) {
                $this->$property = $data[$property];
            }
        }

    }

    public function __get( string $name ): mixed {

        if( in_array($name, $this->getPropertyList()) ) {
            return $this->$name;
        }

        return null;

    }

    public function __isset(string $name): bool {

        if( in_array($name, $this->getPropertyList()) ) {
            return isset($this->$name);
        }

        return false;

    }

    public function __set( string $name, mixed $value ): void {

        if( !in_array($name, $this->getPropertyList()) ) {
            throw RuntimeException("Invalid property: $name");
        }

        $method = "set{$name}";

        // TODO: we should probably evaluate the setter list at construction time / first-use, similar to getPropertyList
        if( !method_exists($this, $method) ) {
            throw new RuntimeException("Can't set immutable property:` $name");
        }

        $this->$method($value);

    }

    public function __unset(string $name): void {
        $this->__set($name, null);
    }

    /**
     * Get a list of model properties.
     * Valid properties are defined as protected and don't begin with an underscore.
     *
     * @return array
     */
    protected function getPropertyList(): array {

        // we use reflection to get a list of properties but we only need to run
        // the reflection code once...
        static $props = [];

        // get a list of protected properties from the implementing class
        // these will be used to hold the various properties of the model
        if( empty($props) ) {

            $p = (new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PROTECTED);

            foreach( $p as $property ) {

                $k = $property->getName();

                if( str_starts_with($k, '_') ) {
                    continue;
                }

                $props[] = $k;

            }

        }

        return $props;

    }

    protected function setID( mixed $id ): void {

        if( !empty($id) ) {
            throw new RuntimeException("ID is already set");
        }

        $this->id = $id;

    }

}
