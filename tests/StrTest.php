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

    /**
     * @return array<string, array{string, string}>
     */
    public static function uncamelProvider(): array {
        return [
            'camel and separators' => ['helloWorld Foo-Bar', 'hello_world_foo_bar'],
            'acronym'              => ['XMLHttpRequest', 'xml_http_request'],
            'already snake'        => ['already_snake', 'already_snake'],
            'separator runs'       => ['a -- b..c', 'a_b_c'],
            'unicode letters kept' => ['café-Bar', 'café_bar'],
            'nfd marks kept'       => ["cafe\u{0301}-Bar", "cafe\u{0301}_bar"],
            'nfd not split'        => ["re\u{0301}sume\u{0301}Draft", "re\u{0301}sume\u{0301}draft"],
            'digits'               => ['version2Beta', 'version2_beta'],
        ];
    }

    #[DataProvider('uncamelProvider')]
    public function testUncamelise(string $input, string $expected): void {
        $this->assertSame($expected, Str::uncamelise($input));
    }

    public function testXssCleanIsRemoved(): void {
        $this->assertFalse(method_exists(Str::class, 'xssClean'));
    }

    public function testStripControlChars(): void {
        $this->assertSame("ab\tc\n", Str::stripControlChars("a\x00b\tc\x7F\n"));
    }

    public function testNormaliseLineEndings(): void {
        $this->assertSame("a\nb\n\nc", Str::normaliseLineEndings("a\r\nb\r\r\r\nc"));
    }

    public function testParseURLReturnsAllComponents(): void {
        $this->assertSame(
            [
                'scheme' => 'https',
                'host'   => 'example.com',
                'port'   => 8080,
                'user'   => 'user',
                'pass'   => 'pass',
                'path'   => '/path',
                'query'  => ['a' => '1', 'b' => 'x&y'],
            ],
            Str::parseURL('https://user:pass@example.com:8080/path?a=1&b=x%26y'),
        );
    }

    public function testParseURLFillsMissingPartsFromDefaults(): void {
        $this->assertSame(
            [
                'scheme' => 'mysql',
                'host'   => 'db',
                'port'   => 3306,
                'user'   => '',
                'pass'   => '',
                'path'   => '',
                'query'  => [],
            ],
            Str::parseURL('mysql://db', ['port' => 3306]),
        );
    }

    public function testParseURLReturnsNullForInvalidURL(): void {
        $this->assertNull(Str::parseURL('http://:80'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function encodedCredentialsProvider(): array {
        return [
            'encoded slash'    => ['mysql://u:p%2Fss@host/db', 'u', 'p/ss'],
            'encoded hash'     => ['mysql://u:p%23ss@host/db', 'u', 'p#ss'],
            'encoded at'       => ['mysql://u:p%40ss@host/db', 'u', 'p@ss'],
            'literal plus'     => ['mysql://u:p+ss@host/db', 'u', 'p+ss'],
            'encoded user'     => ['mysql://u%40x:p%3Ass@host/db', 'u@x', 'p:ss'],
            'everything mixed' => ['mysql://u%40x:p%2Fs%23s%2B@host/db', 'u@x', 'p/s#s+'],
        ];
    }

    #[DataProvider('encodedCredentialsProvider')]
    public function testParseURLDecodesCredentials(string $url, string $user, string $pass): void {
        $parts = Str::parseURL($url);

        $this->assertIsArray($parts);
        $this->assertSame($user, $parts['user']);
        $this->assertSame($pass, $parts['pass']);
        $this->assertSame('host', $parts['host']);
        $this->assertSame('/db', $parts['path']);
    }

    public function testParseURLDecodesHostAndPathOnce(): void {
        $parts = Str::parseURL('pgsql://my%2Dhost/my%20db%2541');

        $this->assertIsArray($parts);
        $this->assertSame('my-host', $parts['host']);
        // %2541 is an encoded '%41' and must not be decoded a second time into 'A'
        $this->assertSame('/my db%41', $parts['path']);
    }

    public function testParseURLAcceptsEmptyAuthority(): void {
        $parts = Str::parseURL('sqlite:///var/data/app.db', ['host' => 'fallback']);

        $this->assertIsArray($parts);
        $this->assertSame('sqlite', $parts['scheme']);
        $this->assertSame('fallback', $parts['host']);
        $this->assertSame('/var/data/app.db', $parts['path']);
    }

    public function testParseURLRejectsFourSlashes(): void {
        // only exactly three slashes mean an empty authority; four must not turn 'abs' into the host
        $this->assertNull(Str::parseURL('sqlite:////abs/path.db'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array {
        return [
            'host and path'       => ['mysql://h/db'],
            'full'                => ['mysql://user:secret@db.example.com:3307/app'],
            'encoded credentials' => ['mysql://u%40x:p%2Fs%23s%2B@host/db'],
            'user only'           => ['pgsql://admin@host/db'],
            'empty authority'     => ['sqlite:///var/x.db'],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testBuildURLRoundTripsParseURL(string $url): void {
        $parts = Str::parseURL($url);

        $this->assertIsArray($parts);
        $this->assertSame($url, Str::buildURL($parts, true));
    }

    public function testBuildURLMasksPasswordByDefault(): void {
        $parts = Str::parseURL('mysql://user:p%2Fss@host/db');

        $this->assertIsArray($parts);
        $this->assertSame('mysql://user:<password>@host/db', Str::buildURL($parts));
    }

}
