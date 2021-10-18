<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\query;

use spf\contracts\database\DatabaseConnection;

/**
 * Generic delete query.
 */
class Delete extends BaseQuery {

    use Common;

    protected string $from = '';

    public function from( string $table ): static {
        $this->from = $this->quoteIdentifier($table);
        return $this;
    }

    protected function compile(): array {

        return array_merge(
            [
                "DELETE FROM {$this->from}",
            ],
            $this->compileWhere(),
            $this->compileOrderBy(),
            $this->compileLimit()
        );

    }

}
