<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use BadMethodCallException;

use spf\contracts\database\DatabaseConnection;

/**
 * Generic select query.
 */
class Select extends BaseQuery {

    use Common;

    protected array|string $columns = [];
    protected bool $distinct = false;
    protected string $from = '';
    protected array $joins = [];
    protected array $group_by = [];
    protected array $having = [];
    protected int|null $offset = null;

    /**
     * Specify the columns to be included in the resultset.
     * Each argument is either a column name or tuple of a column name and alias.
     *
     * @param [string|array] ...$columns
     */
    public function cols( ...$columns ): static {

        // default to everything
        if( empty($columns) ) {
            $columns = '*';
        }

        $this->columns = $columns;

        return $this;

    }

    public function colsArray( array $columns ): static {
        return $this->cols(...$columns);
    }

    // use raw columns statement
    public function colsRaw( string $sql ): static {
        $this->columns = $sql;
        return $this;
    }

    public function distinct( bool $distinct = true ): static {
        $this->distinct = (bool) $distinct;
        return $this;
    }

    public function from( string $table ): static {
        $this->from = $this->quoteIdentifier($table);
        return $this;
    }

    public function fromRaw( string $sql ): static {
        $this->from = $sql;
        return $this;
    }

    public function innerJoin( string $table, array $on ): static {
        $this->joins[] = ['INNER', $table, $on];
        return $this;
    }

    public function leftJoin( string $table, array $on ): static {
        $this->joins[] = ['LEFT', $table, $on];
        return $this;
    }

    public function joinRaw( string $sql, array $parameters = [] ): static {
        $this->joins[] = $sql;
        $this->params = array_merge($this->params, $parameters);
        return $this;
    }

    public function groupBy( array $columns ): static {

        foreach( $columns as $column ) {
            $this->group_by[] = $this->quoteIdentifier($column);
        }

        return $this;

    }

    public function having( string $having ): static {
        $this->having = [$having];
        return $this;
    }

    public function offset( int $offset ): static {
        $this->offset = max(0, (int) $offset);
        return $this;
    }

    public function __call( string $method, array $args ): mixed {

        if( !in_array($method, ['getOne', 'getCol', 'getRow', 'getAssoc', 'getAll']) ) {
            throw new BadMethodCallException("Unknown Method: {$method}");
        }

        return $this->db->$method((string) $this, $this->params);

    }

    public function compile(): array {

        $columns = $this->columns;

        if( is_array($columns) ) {
            $columns = $this->compileColumns($columns);
        }

        return array_merge(
            [
                ($this->distinct ? 'SELECT DISTINCT' : 'SELECT'). ' '. $columns,
                $this->from ? 'FROM '. $this->from : '',
            ],
            $this->compileJoins(),
            $this->compileWhere(),
            $this->compileGroupBy(),
            $this->having,
            $this->compileOrderBy(),
            $this->compileLimit(),
            $this->compileOffset(),
        );

    }

    protected function compileColumns( array $columns ): string {

        foreach( $columns as &$col ) {

            // if column is an array is should have two elements
            // the first being the column name, the second being the alias
            if( is_array($col) ) {
                list($column, $alias) = $col;
                $col = sprintf(
                    '%s AS %s',
                    $this->quoteIdentifier($column),
                    $this->db->quoteIdentifier($alias)
                );
            }
            else {
                $col = $this->quoteIdentifier($col);
            }

        }

        return implode(",\n", $columns);

    }

    protected function compileJoins(): array {

        $sql = [];

        foreach( $this->joins as $join ) {
            if( is_array($join) ) {
                list($type, $table, $on) = $join;
                $join = sprintf("%s JOIN %s\nON %s", $type, $this->quoteIdentifier($table), $this->compileOn($on));
            }
            $sql[] = $join;
        }

        return $sql;

    }

    protected function compileOn( array $on ): string {

        $sql = [];

        foreach( $on as $column => $value ) {
            // if it's not a number or a quoted sring it much be an identifier, so quote it
            if( !is_numeric($value) && !preg_match('/^\'.*\'$/', $value) ) {
                $value = $this->quoteIdentifier($value);
            }
            $sql[] = sprintf("%s = %s", $this->quoteIdentifier($column), $value);
        }

        return implode("\nAND ", $sql);

    }

    protected function compileGroupBy(): array {

        $sql = [];

        if( $this->group_by ) {
            $sql[] = 'GROUP BY '. implode(', ', $this->group_by);
        }

        return $sql;

    }

    protected function compileOffset(): array {

        $sql = [];

        if( isset($this->offset) ) {
            $sql[] = sprintf(
                "OFFSET %s",
                $this->bindParam('__offset', $this->offset),
            );
        }

        return $sql;

    }
}
