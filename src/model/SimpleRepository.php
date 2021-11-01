<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\model;

use RuntimeException;

use spl\contracts\database\DatabaseConnection;
use spl\contracts\database\Query;
use spl\contracts\model\Repository;
use spl\contracts\model\Model;

abstract class SimpleRepository implements Repository {

    protected const MODEL_CLASS = '';
    protected const TABLE_NAME = '';
    protected const ID_COLUMN = 'id';

    public function __construct( protected DatabaseConnection $db ) {

        if( empty(static::MODEL_CLASS) ) {
            throw new RuntimeException("Missing MODEL_CLASS ". static::class);
        }

        if( empty(static::TABLE_NAME) ) {
            throw new RuntimeException("Missing TABLE_NAME for ". static::class);
        }

        if( empty(static::ID_COLUMN) ) {
            throw new RuntimeException("Missing ID_COLUMN for ". static::class);
        }

    }

    public function fetch( int|string $id ): Model {

        $class = static::MODEL_CLASS;

        return new $class($this->getBaseQuery()
            ->where(static::ID_COLUMN, $id)
            ->getRow()
        );

    }

    public function save( Model $model ): void {

        if( empty($model->id) ) {

            $this->db->insert()
                ->into(static::TABLE_NAME)
                ->item()
                ->execute()
            ;

            // model doesn't have an id so use the last insert id from the database connection
            $model->id = $this->db->lastInsertId();
        }
        else {

            $this->db->update()
                ->table(static::TABLE_NAME)
                ->set()
                ->where(static::ID_COLUMN, $id)
                ->execute()
            ;

        }

    }

    public function delete( Model $model ): void {

        $this->db->delete()
            ->from(static::TABLE_NAME)
            ->where(static::ID_COLUMN, $model->id)
            ->execute()
        ;

    }

    protected function getBaseQuery(): Query {

        return $this->db->select()->from(static::TABLE_NAME);

    }

}
