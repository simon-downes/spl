<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use spf\contracts\database\DatabaseConnection;

/**
 * Generic insert query.
 */
class Insert extends BaseQuery {

    protected bool $ignore = false;
    protected string $into = '';
    protected array $columns = [];
    protected array $values = [];

    public function ignore( bool $ignore = true ): static {
        $this->ignore = $ignore;
        return $this;
    }

    public function into( string $table ): static {
        $this->into = $this->quoteIdentifier($table);
        return $this;
    }

    public function item( array $item ): static {

        if( empty($this->columns) ) {
            $this->columns = array_keys($item);
        }

        $values = [];
        $index  = count($this->values) + 1;

        foreach( $item as $column => $value ) {
            $column = "{$column}_{$index}";
            $values[] = ":{$column}";
            $this->params[$column] = $value;
        }

        $this->values[] = $values;

        return $this;

    }

    public function items( array $items ): static {

        foreach( $items as $item ) {
            $this->item($item);
        }

        return $this;

    }

    public function execute( bool $return_insert_id = true ): int|string {

        $result = $this->db->execute((string) $this, $this->params);

        if( $return_insert_id ) {
            $result = $this->db->insertId();
        }

        return $result;

    }

    protected function compile(): array {

        $sql = [
            ($this->ignore ? 'INSERT IGNORE' : 'INSERT'). ' INTO'. $this->into,
            '('. implode(', ', $this->columns). ')',
            'VALUES',
        ];

        foreach( $this->values as $list ) {
            $sql[] = sprintf('(%s),', implode(', ', $list));
        }

        // remove comma from last values item
        $tmp = substr(array_pop($sql), 0, -1);
        array_push($sql, $tmp);

        return $sql;

    }

}
