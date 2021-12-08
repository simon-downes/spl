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
use spl\contracts\support\Criteria;

use spl\support\SimpleCriteria;

abstract class SimpleRepository implements Repository {

    protected const MODEL_CLASS = '';
    protected const TABLE_NAME  = '';
    protected const ID_COLUMN   = 'id';
    protected const COLUMN_MAP  = [];

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

    /**
     * Find the first model with a property that matches the specified value.
     */
    public function findOneBy( string $property, mixed $value ): Model {

        $item = reset($this->findBy($property, $value));

        if( !$item ) {
            return null;
        }

        return $item;
    }

    /**
     * Find all models with a property that matches the specified value.
     */
    public function findBy( string $property, mixed $value ): array {
        return $this->find(function( Criteria $criteria ) use ($property, $value) {

            $method = "eq";

            if( is_array($value) ) {
                $method = "in";
            }

            return $criteria->$method($property, $value);

        });
    }

    /**
     * Find a models that match the specified criteria.
     *
     * @param callable $get_criteria
     * @return array
     */
    public function find( callable $get_criteria ): array {

        // run the callback to populate the criteria
        $criteria = $get_criteria($this->makeCriteria());

        // create the base select query
        $q = $this->getBaseQuery();

        // for each criteria item, create an appropriate where clause
        foreach( $criteria->getCriteria() as $item ) {

            [$property, $operator, $value] = $item;

            // TODO: handle IN and NOT IN

            $q->where($this->getColumnName($property), $operator, $value);

        }

        // apply ordering
        foreach( $criteria->getOrder() as $property => $direction ) {
            $q->orderBy(
                $this->getColumnName($property),
                $direction == Criteria::ORDER_ASC
            );
        }

        // apply offset and limit values
        $q->offset($criteria->getOffset());
        $q->limit($criteria->getLimit());

        return $this->makeModels($q->getAll());

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

    protected function makeModels( array $items ): array {

        $models = [];

        foreach( $items as $item ) {
            $models[] = new (static::MODEL_CLASS)($item);
        }

        return $models;

    }

    /**
     * Return a Select query to be used as the basis of findX() methods.
     */
    protected function getBaseQuery(): Query {


        return $this->db->select()->from(static::TABLE_NAME);
    }

    /**
     * Return a Criteria instance that is used to filter results to findX() methods.
     */
    protected function makeCriteria(): Criteria {

        return new SimpleCriteria();

    }

    /**
     * Map a model property (or criteria field) to a database column in the base query.
     */
    public function getColumnName( string $criteria ): string {

        return static::COLUMN_MAP[$criteria] ?? $criteria;

    }

}
