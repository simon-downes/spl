<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use spl\Str;

final class StrTest extends TestCase {

    public function testSlugify(): void {
        $this->assertSame('hello-world-this-is-a-test', Str::slugify('Hello World! This is a test'));
        $this->assertSame('cafe-and-creme', Str::slugify('Café & Crème'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pluralProvider(): array {
        return [
            'regular'     => ['user', 'users'],
            'y to ies'    => ['category', 'categories'],
            'es suffix'   => ['box', 'boxes'],
            'irregular'   => ['child', 'children'],
            'uncountable' => ['sheep', 'sheep'],
        ];
    }

    #[DataProvider('pluralProvider')]
    public function testPluralise(string $singular, string $plural): void {
        $this->assertSame($plural, Str::pluralise($singular));
    }

    #[DataProvider('pluralProvider')]
    public function testSingularise(string $singular, string $plural): void {
        $this->assertSame($singular, Str::singularise($plural));
    }

    /**
     * @return array<int, array{int, string}>
     */
    public static function ordinalProvider(): array {
        return [
            [1, '1st'],
            [2, '2nd'],
            [3, '3rd'],
            [4, '4th'],
            [11, '11th'],
            [12, '12th'],
            [13, '13th'],
            [21, '21st'],
            [102, '102nd'],
            [111, '111th'],
        ];
    }

    #[DataProvider('ordinalProvider')]
    public function testOrdinal(int $n, string $expected): void {
        $this->assertSame($expected, Str::ordinal($n));
    }

}
