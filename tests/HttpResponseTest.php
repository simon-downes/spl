<?php declare(strict_types=1);

namespace spl\tests;

use Error;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use spl\HttpResponse;

final class HttpResponseTest extends TestCase {

    public static function okProvider(): array {
        return [
            '199' => [199, false],
            '200' => [200, true],
            '204' => [204, true],
            '299' => [299, true],
            '302' => [302, false],
            '404' => [404, false],
            '500' => [500, false],
        ];
    }

    #[DataProvider('okProvider')]
    public function testOk(int $status, bool $expected): void {
        $this->assertSame($expected, self::response($status)->ok());
    }

    public function testJsonDecodesBodyToArrays(): void {
        $this->assertSame(['a' => ['b' => 1]], self::response(200, '{"a":{"b":1}}')->json());
    }

    public function testJsonDecodesScalars(): void {
        $this->assertSame('text', self::response(200, '"text"')->json());
    }

    public function testJsonThrowsOnInvalidBody(): void {
        $this->expectException(JsonException::class);
        self::response(200, '<html>')->json();
    }

    public function testJsonThrowsOnEmptyBody(): void {
        $this->expectException(JsonException::class);
        self::response(204, '')->json();
    }

    public function testPropertiesAreReadonly(): void {

        $response = self::response(200, 'body');

        $this->expectException(Error::class);

        $response->body = 'changed';

    }

    private static function response(int $status, string $body = ''): HttpResponse {
        return new HttpResponse('1.1', $status, 'Status', ['content-type' => 'text/plain'], $body);
    }

}
