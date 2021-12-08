<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\support;

use spl\contracts\support\Criteria;

class SimpleCriteria implements Criteria {

    protected array $criteria;
    protected array $order;
    protected int $offset;
    protected int $limit;

    public function __construct(
        protected array $allowed = []
    ) {
        $this->clear();
    }

    public function getCriteria(): array {
        return $this->criteria;
    }

    public function getOrder(): array {
        return $this->order;
    }

    public function getOffset(): int {
        return $this->offset;
    }

    public function getLimit(): int {
        return $this->limit;
    }

    public function clear(): static {

        $this->criteria = [];
        $this->order    = [];
        $this->offset   = 0;
        $this->limit    = 100;

        return $this;

    }

    public function eq( string $field, mixed $value ): static {
        $this->addCriteria($field, '=', $value);
        return $this;
    }

    public function ne( string $field, mixed $value ): static {
        $this->addCriteria($field, '<>', $value);
        return $this;
    }

    public function lt( string $field, mixed $value ): static {
        $this->addCriteria($field, '<', $value);
        return $this;
    }

    public function lte( string $field, mixed $value ): static {
        $this->addCriteria($field, '<=', $value);
        return $this;
    }

    public function gt( string $field, mixed $value ): static {
        $this->addCriteria($field, '>', $value);
        return $this;
    }

    public function gte( string $field, mixed $value ): static {
        $this->addCriteria($field, '>=', $value);
        return $this;
    }

    public function in( string $field, array $values ): static {
        $this->addCriteria($field, 'IN', $values);
        return $this;
    }

    public function notIn( string $field, array $values ): static {
        $this->addCriteria($field, 'NOT IN', $values);
        return $this;
    }

    public function like( string $field, string $value ): static {
        $this->addCriteria($field, 'LIKE', $value);
        return $this;
    }

    public function notLike( string $field, string $value ): static {
        $this->addCriteria($field, 'NOT LIKE', $value);
        return $this;
    }

    public function matches( string $field, mixed $value ): static {
        $this->addCriteria($field, 'MATCHES', $value);
        return $this;
    }

    public function orderBy( string $field, string $direction = self::ORDER_ASC ): static {

        if( !in_array($direction, [static::ORDER_ASC, static::ORDER_DESC]) ) {
            throw new CriteriaException("Invalid orderBy direction: $direction");
        }

        $this->order[$field] = $direction;

        return $this;

    }

    public function offset( int $offset ): static {

        if( $offset < 0 ) {
            throw new CriteriaException("Invalid offset '{$offset}', must be >= 0");
        }

        $this->offset = $offset;

        return $this;

    }

    public function limit( int $limit ): static {

        if( $limit <= 0 ) {
            throw new CriteriaException("Invalid limit '{$limit}', must be > 0");
        }

        $this->limit = $limit;

        return $this;

    }

    protected function addCriteria( string $field, string $operator, mixed $value ): void {

        if( $this->allowed && !in_array($field, $this->allowed) ) {
            throw new CriteriaException("Invalid criteria: {$field}");
        }

        $this->criteria[] = [$field, $operator, $value];

    }

}
