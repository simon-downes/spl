<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */

namespace spl\util;

use Throwable;
use ErrorException;
use ReflectionClass;
use ReflectionObject;
use RuntimeException;

class Debug {

    protected int $depth;

    protected array $stack;

    public function __construct() {
        $this->depth  = 0;
        $this->stack  = [];
    }

    public function toString(mixed $var): string {

        $getters = [
            'is_null'     => 'getNull',
            'is_bool'     => 'getBoolean',
            'is_integer'  => 'getInteger',
            'is_float'    => 'getFloat',
            'is_string'   => 'getString',
            'is_array'    => 'getArray',
            'is_object'   => 'getObject',
            'is_resource' => 'getResource',
        ];

        $result = '';

        foreach ($getters as $test => $method) {
            if ($test($var)) {
                /** @phpstan-ignore-next-line */
                $result = $this->$method($var);
                break;
            }
        }

        // if result is empty then $var didn't fall into one of the know types
        // so we just fallback to var_dump()
        if (empty($result)) {
            ob_start();
            var_dump($var);
            $result = ob_get_clean();
            if ($result === false) {
                throw new RuntimeException('Failed to capture output from var_dump()');
            }
        }

        return $result;

    }

    public function getNull( mixed $var): string {
        return 'null';
    }

    public function getBoolean(bool $var): string {
        return sprintf('bool(%s)', $var ? 'true' : 'false');
    }

    public function getInteger(int $var): string {
        return "int({$var})";
    }

    public function getFloat(float $var): string {
        return "float({$var})";
    }

    public function getString(string $str): string {
        $enc = mb_detect_encoding($str, ['UTF-8', 'WINDOWS-1252', 'ISO-8859-1', 'ASCII'], true);
        $enc = ($enc == 'ASCII') ? '' : "; $enc";
        return sprintf('string(%d%s) "%s"', strlen($str), $enc, $str);
    }

    public function getArray(array $arr): string {

        $this->depth++;

        $item = sprintf("array(%d) {\n", count($arr));
        foreach ($arr as $k => $v) {
            $item .= sprintf("%s[%s] => %s\n", str_repeat("\t", $this->depth), $k, $this->toString($v));
        }
        $item .= str_repeat("\t", $this->depth - 1) . "}";

        $this->depth--;

        return $item;

    }

    public function getObject(object $obj): string {

        if ($item = $this->recursionCheck($obj)) {
            return $item;
        }
        elseif ($obj instanceof Throwable) {
            return $this->getThrowable($obj);
        }

        $this->stack[] = $obj;

        $this->depth++;

        $item = get_class($obj) . " {\n";

        $item .= $this->getObjectProperties($obj);

        $item .= str_repeat("\t", $this->depth - 1) . "}";

        $this->depth--;

        array_pop($this->stack);

        return $item;

    }

    public function getThrowable(Throwable $e): string {

        $item = get_class($e);

        $meta = [
            'message'  => $e->getMessage(),
            'code'     => $e->getCode(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => $this->getTrace($e->getTrace()),
            'previous' => $e->getPrevious(),
        ];

        if ($e instanceof ErrorException) {
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

        $item .= $this->getMeta($meta);

        return $item;

    }

    /** @phpstan-ignore-next-line  resources have no type hint */
    public function getResource($resource): string {

        $type = get_resource_type($resource);

        $item = (string) $resource;
        $item = sprintf("resource(%s; %s)", substr($item, strpos($item, '#')), $type);

        // try and get some additional info about the resource
        switch ($type) {
            case 'stream':
                $item .= $this->getMeta(
                    stream_get_meta_data($resource),
                );
                break;

            case 'curl':
                $item .= $this->getMeta(
                    curl_getinfo($resource),
                );
                break;

        }

        return $item;

    }

    protected function getMeta(array $meta): string {

        $this->depth++;

        $width = max(array_map('strlen', array_keys($meta))) + 1;

        $item = " {\n";
        foreach ($meta as $k => $v) {
            $item .= sprintf("%s%s: %s\n", str_repeat("\t", $this->depth), str_pad(ucwords(str_replace('_', ' ', $k)), $width), $this->toString($v));
        }
        $item .= str_repeat("\t", $this->depth - 1) . "}";

        $this->depth--;

        return $item;

    }

    protected function getTrace(array $trace): array {

        $lines = [];

        foreach ($trace as $i => $frame) {

            $line = '';

            if (isset($frame['class'])) {
                $line .= $frame['class'] . $frame['type'];
            }

            $line .= $frame['function'] . '()';

            if (isset($frame['file'])) {
                $line .= ' [' . $frame['file'];
                if (isset($frame['line'])) {
                    $line .= ':' . $frame['line'];
                }
                $line .= ']';
            }

            $lines[] = $line;

        }

        return $lines;

    }

    protected function getObjectProperties(object $obj): string {

        // we use reflection to access all the object's properties (public, protected and private)
        $r = new ReflectionObject($obj);

        $item = '';

        foreach ($this->getClassProperties($r) as $p) {
            $p->setAccessible(true);
            $item .= sprintf("%s%s: %s\n", str_repeat("\t", $this->depth), $p->name, $this->toString($p->getValue($obj)));
        }

        return $item;

    }

    protected function getClassProperties(ReflectionClass $class): array {

        $properties = [];

        foreach ($class->getProperties() as $property) {
            $properties[$property->getName()] = $property;
        }

        if ($parent = $class->getParentClass()) {
            $parent_props = $this->getClassProperties($parent);
            if (count($parent_props) > 0) {
                $properties = array_merge($parent_props, $properties);
            }
        }

        return $properties;

    }

    protected function recursionCheck(object $obj): string {

        if (end($this->stack) === $obj) {
            return '**SELF**';
        }
        elseif (in_array($obj, $this->stack, true)) {
            return '**RECURSION**';
        }

        return '';

    }

}
