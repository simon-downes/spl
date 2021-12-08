<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\support;

interface Criteria {

	public const ORDER_ASC = 'ASC';
	public const ORDER_DESC = 'DESC';

    /**
     * Returns an array of tuples containing the field, operator and value(s) of each criterion:
     * ['field', 'operator', 'value']
     *
     * @return array
     */
    public function getCriteria(): array;

    /**
     * Returns an array containing the desired order of results in the form:
     * field => direction
     *
     * @return array
     */
    public function getOrder(): array;

    public function getOffset(): int;
    public function getLimit(): int;

    public function clear(): static;

    public function eq( string $field, mixed $value ): static;
    public function ne( string $field, mixed $value ): static;
    public function lt( string $field, mixed $value ): static;
    public function lte( string $field, mixed $value ): static;
    public function gt( string $field, mixed $value ): static;
    public function gte( string $field, mixed $value ): static;
    public function in( string $field, array $values ): static;
    public function notIn( string $field, array $values ): static;
    public function like( string $field, string $value ): static;
    public function notLike( string $field, string $value ): static;
    public function matches( string $field, string $regex ): static;

    public function orderBy( string $field, string $direction = self::ORDER_DESC ): static;

    public function offset( int $offset ): static;
    public function limit( int $limit ): static;

}
