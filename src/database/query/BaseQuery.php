<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use LogicException;

use spl\contracts\database\DatabaseConnection;
use spl\contracts\database\Query;

/**
 * Generic query class.
 */
abstract class BaseQuery implements Query {

    /**
     * Array of query parameters.
     */
    protected array $params = [];

    public function __construct( protected DatabaseConnection $db ) {
    }

    public function __toString(): string {
        return implode("\n", $this->compile());
    }

    public function getParameters(): array {
        return $this->params;
    }

    public function setParameters( array $params, $replace = false ): Query {

        if( $replace ) {
            $this->params = [];
        }

        $this->params = array_merge($this->params, $params);

        return $this;

    }

    /**
     * Generate a SQL string as an array.
     */
    abstract protected function compile(): array;

    protected function quoteIdentifier( string $spec ): string {

        // don't quote things that are functions/expressions
        if( strpos($spec, '(') !== false ) {
            return $spec;
        }

        foreach( [' AS ', ' ', '.'] as $sep) {
            if( $pos = strripos($spec, $sep) ) {
                return
                    $this->quoteIdentifier(substr($spec, 0, $pos)).
                    $sep.
                    $this->db->quoteIdentifier(substr($spec, $pos + strlen($sep)));
            }
        }

        return $this->db->quoteIdentifier($spec);

    }

    /**
     * Join an array of values to form a string suitable for use in a SQL IN clause.
     * The numeric parameter determines whether values are escaped and quoted;
     * a null value (the default) will cause the function to auto-detect whether
     * values should be escaped and quoted.
     */
    protected function makeInClause( array $values, $numeric = null ): string {

        // if numeric flag wasn't specified then detected it
        // by checking all items in the array are numeric
        if( $numeric === null ) {
            $numeric = count(array_filter($values, 'is_numeric')) == count($values);
        }

        // not numeric so we need to escape all the values
        if( !$numeric ) {
            $values = array_map([$this->db, 'quote'], $values);
        }

        return sprintf('(%s)', implode(', ', $values));

    }

    /**
     * Generate a name for a parameter based on the column and operator.
     */
    protected function getParameterName( string $column, string $operator ): string {

        $suffixes = [
            '='    => 'eq',
            '!='   => 'neq',
            '<>'   => 'neq',
            '<'    => 'max',
            '<='   => 'max',
            '>'    => 'min',
            '>='   => 'min',
            'LIKE' => 'like',
            'NOT LIKE' => 'notlike',
        ];

        $name = $column;

        // strip the table identifier
        if( $pos = strpos($name, '.') ) {
            $name = substr($name, $pos + 1);
        }

        if( isset($suffixes[$operator]) ) {
            $name .= '_'. $suffixes[$operator];
        }

        return $name;

    }

    /**
     * Add a parameter and return the placeholder to be inserted into the query string.
     */
    protected function bindParam( string $name, $value ): string {

        if( isset($this->params[$name]) ) {
            throw new LogicException("Parameter: {$name} has already been defined");
        }

        $this->params[$name] = $value;

        return ":{$name}";

    }

}
