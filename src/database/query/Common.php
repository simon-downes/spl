<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use LogicException;

use spf\contracts\database\DatabaseConnection;

/**
 * Common query functionality.
 */
trait Common {

    /**
     * Array of where clauses.
     */
    protected array $where = [];

    /**
     * Array of order by clauses.
     */
    protected array $order = [];

    /**
     * Query results limit.
     */
    protected int|null $limit = null;

    public function where( string $column, $operator, $value = null ): static {

        // shortcut for equals
        if( func_num_args() == 2 ) {
            $value    = $operator;
            $operator = '=';
        }

        $operator = trim(strtoupper($operator));

        // can't bind IN values as parameters so we escape them and embed them directly
        if( in_array($operator, ['IN', 'NOT IN']) && is_array($value) ) {
            $value = $this->makeInClause($value);
        }
        // do parameter binding
        else {
            $value = $this->bindParam(
                $this->getParameterName($column, $operator),
                $value
            );
        }

        $this->where[] = [$this->quoteIdentifier($column), $operator, $value];

        return $this;

    }

    public function whereArray( array $where ): static {

        foreach( $where as $k => $v ) {
            $operator = is_array($v) ? 'IN' : '=';
            $this->where($k, $operator, $v);
        }

        return $this;

    }

    public function whereRaw( string $sql, array $parameters = [] ): static {
        $this->where[] = $sql;
        $this->params = array_merge($this->params, $parameters);
        return $this;
    }

    public function orderBy( string $column, bool $ascending = true ): static {
        $column = $this->quoteIdentifier($column);
        $this->order[$column] = (bool) $ascending ? 'ASC' : 'DESC';
        return $this;
    }

    public function limit( int $limit ): static {
        $this->limit = max(1, (int) $limit);
        return $this;
    }

    protected function compileWhere(): array {

        $sql = [];

        foreach( $this->where as $i => $clause ) {
            if( is_array($clause) ) {
                $clause = implode(' ', $clause);
            }
            $sql[] = ($i ? 'AND ' : 'WHERE '). $clause;
        }

        return $sql;

    }

    protected function compileOrderBy(): array {

        $sql = [];

        if( $this->order ) {
            $order = 'ORDER BY ';
            foreach( $this->order as $column => $dir ) {
                $order .= $column. ' '. $dir. ', ';
            }
            $sql[] = trim($order, ', ');
        }

        return $sql;

    }

    protected function compileLimit(): array {

        $sql = [];

        if( isset($this->limit) ) {
            $sql[] = sprintf(
                "LIMIT %s",
                $this->bindParam('__limit', $this->limit),
            );
        }

        return $sql;

    }

}
