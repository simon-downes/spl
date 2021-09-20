<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\support;

use Throwable, ErrorException, ReflectionClass, ReflectionObject;

use spl\contracts\support\Dumpable;

class VarDumper {

    protected $depth;

    protected $stack;

    public function __construct() {
        $this->depth  = 0;
        $this->stack  = [];
    }

    public function dump( $var ): string {

        $dumpers = [
            'is_null'     => 'dumpNull',
            'is_bool'     => 'dumpBoolean',
            'is_integer'  => 'dumpInteger',
            'is_float'    => 'dumpFloat',
            'is_string'   => 'dumpString',
            'is_array'    => 'dumpArray',
            'is_object'   => 'dumpObject',
            'is_resource' => 'dumpResource',
        ];

        $result = '';

        foreach( $dumpers as $test => $method ) {
            if( $test($var) ) {
                $result = $this->$method($var);
                break;
            }
        }

        // if result is empty then $var didn't fall into one of the know types
        // so we just fallback to var_dump()
        if( empty($result) ) {
            ob_start();
            var_dump($var);
            $result = ob_get_clean();
        }

        return $result;

    }

    public function dumpNull(): string {
        return 'null';
    }

    public function dumpBoolean( bool $var ): string {
        return sprintf('bool(%s)', $var ? 'true' : 'false');
    }

    public function dumpInteger( int $var ): string {
        return "int({$var})";
    }

    public function dumpFloat( float $var ): string {
        return "float({$var})";
    }

    public function dumpString( string $str ): string {
        $enc = mb_detect_encoding($str, ['UTF-8', 'WINDOWS-1252', 'ISO-8859-1', 'ASCII'], true);
        $enc = ($enc == 'ASCII') ? '' : "; $enc";
        return sprintf('string(%d%s) "%s"', strlen($str), $enc, $str);
    }

    public function dumpArray( array $arr ): string {

        $this->depth++;

        $item = sprintf("array(%d) {\n", count($arr));
        foreach( $arr as $k => $v ) {
            $item .= sprintf("%s[%s] => %s\n", str_repeat("\t", $this->depth), $k, $this->dump($v));
        }
        $item .= str_repeat("\t", $this->depth - 1). "}";

        $this->depth--;

        return $item;

    }

    public function dumpObject( object $obj ): string {

        if( $item = $this->recursionCheck($obj) ) {
            return $item;
        }
        elseif( $obj instanceof Dumpable ) {
            $item = $obj->dump($this);
            if( $item ) {
                return $item;
            }
        }
        elseif( $obj instanceof Throwable ) {
            return $this->dumpThrowable($obj);
        }

        $this->stack[] = $obj;

        $this->depth++;

        $item = get_class($obj). " {\n";

        $item .= $this->dumpObjectProperties($obj);

        $item .= str_repeat("\t", $this->depth - 1). "}";

        $this->depth--;

        array_pop($this->stack);

        return $item;

    }

    public function dumpThrowable( Throwable $e ): string {

        $item = get_class($e);

        $meta = [
            'message'  => $e->getMessage(),
            'code'     => $e->getCode(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => $this->dumpTrace($e->getTrace()),
            'previous' => $e->getPrevious(),
        ];

        if( $e instanceof ErrorException ) {
            $lookup = [
                E_ERROR             => 'ERROR',
                E_WARNING           => 'WARNING',
                E_PARSE             => 'PARSE',
                E_NOTICE            => 'NOTICE',
                E_CORE_ERROR        => 'CORE_ERROR',
                E_CORE_WARNING      => 'CORE_WARNING',
                E_COMPILE_ERROR     => 'COMPILE_ERROR',
                E_COMPILE_WARNING   => 'COMPILE_WARNING',
                E_USER_ERROR        => 'USER_ERROR',
                E_USER_WARNING      => 'USER_WARNING',
                E_USER_NOTICE       => 'USER_NOTICE',
                E_STRICT            => 'STRICT',
                E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
                E_DEPRECATED        => 'DEPRECATED',
                E_USER_DEPRECATED   => 'USER_DEPRECATED',
            ];
            $meta = array_merge([
                'severity' => $lookup[$e->getSeverity()],
            ], $meta);
        }

        $item .= $this->dumpMeta($meta);

        return $item;

    }

    public function dumpResource( $resource ): string {

        $type = get_resource_type($resource);

        $item = (string) $resource;
        $item = sprintf("resource(%s; %s)", substr($item, strpos($item, '#')), $type);

        // try and get some additional info about the resource
        switch( $type ) {
            case 'stream':
                $item .= $this->dumpMeta(
                    stream_get_meta_data($resource)
                );
                break;

            case 'curl':
                $item .= $this->dumpMeta(
                    curl_getinfo($resource)
                );
                break;

        }

        return $item;

    }

    protected function dumpMeta( array $meta ): string {

        $this->depth++;

        $width = max(array_map('strlen', array_keys($meta))) + 1;

        $item = " {\n";
        foreach( $meta as $k => $v ) {
            $item .= sprintf("%s%s: %s\n", str_repeat("\t", $this->depth), str_pad(ucwords(str_replace('_', ' ', $k)), $width) , $this->dump($v));
        }
        $item .= str_repeat("\t", $this->depth - 1). "}";

        $this->depth--;

        return $item;

    }

    protected function dumpTrace( array $trace ): array {

        $lines = [];

        foreach( $trace as $i => $frame ) {

            $line = '';

            if( isset($frame['class']) ) {
                $line .= $frame['class']. $frame['type'];
            }

            $line .= $frame['function']. '()';

            if( isset($frame['file']) ) {
                $line .= ' ['. $frame['file'];
                if( isset($frame['line']) ) {
                    $line .= ':'. $frame['line'];
                }
                $line .= ']';
            }

            $lines[] = $line;

        }

        return $lines;

    }

    protected function dumpObjectProperties( object $obj ): string {

        // we use reflection to access all the object's properties (public, protected and private)
        $r = new ReflectionObject($obj);

        $item = '';

        foreach( $this->getClassProperties($r) as $p ) {
            $p->setAccessible(true);
            $item .= sprintf("%s%s: %s\n", str_repeat("\t", $this->depth), $p->name, $this->dump($p->getValue($obj)));
        }

        return $item;

    }

    protected function getClassProperties( ReflectionClass $class ): array {

        $properties = [];

        foreach( $class->getProperties() as $property ) {
            $properties[$property->getName()] = $property;
        }

        if( $parent = $class->getParentClass() ) {
            $parent_props = $this->getClassProperties($parent);
            if(count($parent_props) > 0) {
                $properties = array_merge($parent_props, $properties);
            }
        }

        return $properties;

    }

    protected function recursionCheck( object $obj ): string {

        if( end($this->stack) === $obj ) {
            return '**SELF**';
        }
        elseif( in_array($obj, $this->stack) ) {
            return '**RECURSION**';
        }

        return '';

    }

}
