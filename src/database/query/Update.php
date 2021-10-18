<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use spf\contracts\database\DatabaseConnection;

/**
 * Generic update query.
 */
class Update extends BaseQuery {

    use Common;

    /**
     * Name of table to update.
     */
    protected string $table = '';

    /**
     * Array of columns and values to update.
     */
    protected array $set = [];

    public function table( string $table ): static {
        $this->table = $this->quoteIdentifier($table);
        return $this;
    }

    public function set( array $data, bool $replace = false ): static {

        if( $replace ) {
            $this->set = [];
        }

        $this->set = array_merge($this->set, $data);

        return $this;

    }

    protected function compile(): array {

        return array_merge(
            [
                "UPDATE {$this->table}",
            ],
            $this->compileSet(),
            $this->compileWhere(),
            $this->compileOrderBy(),
            $this->compileLimit()
        );

    }

    protected function compileSet(): array {

        $sql = [];
        $end = -1;

        foreach( $this->set as $column => $value ) {
            $this->bindParam($column, $value);
            $sql[] = "{$column} = :{$column},";
            $end++;
        }

        $sql[0]    = 'SET '. $sql[0];
        $sql[$end] = trim($sql[$end], ',');

        return $sql;

    }

}
