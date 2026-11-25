<?php declare(strict_types=1);

namespace spl\tests\web;

use Exception;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use spl\web\Response;
use spl\web\WebException;

final class ResponseTest extends TestCase {

    public function testHtml(): void {
        $response = Response::html('<h1>Hello</h1>');

        $this->assertSame(['code' => 200, 'message' => 'OK'], $response->getStatus());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('content-type'));
        $this->assertSame('<h1>Hello</h1>', $response->getBody());
    }

    public function testHtmlWithStatus(): void {
        $response = Response::html('<h1>Missing</h1>', 404);

        $this->assertSame(['code' => 404, 'message' => 'Not Found'], $response->getStatus());
    }

    public function testJson(): void {
        $response = Response::json(['status' => 'ok', 'count' => 2], 201);

        $this->assertSame(['code' => 201, 'message' => 'Created'], $response->getStatus());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeader('content-type'));
        $this->assertSame('{"status":"ok","count":2}', $response->getBody());
    }

    public function testText(): void {
        $response = Response::text('plain words');

        $this->assertSame(200, $response->getStatus()['code']);
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('plain words', $response->getBody());
    }

    public function testRedirect(): void {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->getStatus()['code']);
        $this->assertSame('/login', $response->getHeader('location'));
        $this->assertTrue($response->isRedirect());
    }

    public function testPermanentRedirect(): void {
        $response = Response::redirect('/new-home', true);

        $this->assertSame(301, $response->getStatus()['code']);
        $this->assertSame('/new-home', $response->getHeader('location'));
    }

    public function testSetHeaderNormalisesNameAndRemovesOnNull(): void {
        $response = Response::text('x')->setHeader('X-Custom', 'value');

        $this->assertSame('value', $response->getHeader('x-custom'));

        $response->setHeader('X-Custom', null);

        $this->assertNull($response->getHeader('x-custom'));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function addedStatusProvider(): array {
        return [
            '308' => [308, 'Permanent Redirect'],
            '422' => [422, 'Unprocessable Content'],
            '429' => [429, 'Too Many Requests'],
        ];
    }

    #[DataProvider('addedStatusProvider')]
    public function testAddedStatusCodes(int $code, string $message): void {
        $response = Response::html('', $code);

        $this->assertSame(['code' => $code, 'message' => $message], $response->getStatus());
    }

    public function testPermanentRedirectStatusIsRedirect(): void {
        $this->assertTrue(Response::html('', 308)->isRedirect());
    }

    public function testUnknownStatusCodeIsRejected(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('299 is not a valid HTTP status code');

        Response::html('', 299);
    }

    public function testJsonThrowsOnMalformedUtf8(): void {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        Response::json(['x' => "\xB1\x31"]);
    }

    public function testFromThrowableEscapesMessage(): void {
        $response = Response::fromThrowable(WebException::badRequest('<script>alert("x\'1")</script>'));

        $this->assertSame(400, $response->getStatus()['code']);
        $this->assertSame('<h1>&lt;script&gt;alert(&quot;x&#039;1&quot;)&lt;/script&gt;</h1>', $response->getBody());
    }

    public function testFromThrowableUsesServerErrorForOtherExceptions(): void {
        $response = Response::fromThrowable(new RuntimeException('boom'));

        $this->assertSame(500, $response->getStatus()['code']);
        $this->assertTrue($response->isServerError());
    }

    public function testContentLengthIsAString(): void {
        $this->assertSame('3', Response::text('abc')->getHeader('content-length'));
        $this->assertSame('2', Response::text('é')->getHeader('content-length'));
    }

    public function testCookieDefaultsToHttpOnlyAndSameSiteLax(): void {
        $response = Response::text('x')->setCookie('session', 'abc');

        $this->assertSame([
            'value'    => 'abc',
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $response->getCookie('session'));
    }

    public function testCookiePositionalArgumentsStillWork(): void {
        $before = time();
        $cookie = Response::text('x')->setCookie('s', 'v', 60, '/app', 'example.com')->getCookie('s');

        $this->assertIsArray($cookie);
        $this->assertGreaterThanOrEqual($before + 60, $cookie['expires']);
        $this->assertLessThanOrEqual(time() + 60, $cookie['expires']);
        $this->assertSame('/app', $cookie['path']);
        $this->assertSame('example.com', $cookie['domain']);
        $this->assertTrue($cookie['httponly']);
    }

    public function testCookieOptionsCanBeSet(): void {
        $cookie = Response::text('x')
            ->setCookie('s', 'v', secure: true, httponly: false, samesite: 'strict')
            ->getCookie('s');

        $this->assertIsArray($cookie);
        $this->assertTrue($cookie['secure']);
        $this->assertFalse($cookie['httponly']);
        $this->assertSame('Strict', $cookie['samesite']);

        $cookie = Response::text('x')->setCookie('s', 'v', secure: true, samesite: 'NONE')->getCookie('s');

        $this->assertIsArray($cookie);
        $this->assertSame('None', $cookie['samesite']);
    }

    public function testCookieRejectsUnknownSameSite(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SameSite value: bogus');

        Response::text('x')->setCookie('s', 'v', samesite: 'bogus');
    }

    public function testCookieIsRemovedOnNull(): void {
        $response = Response::text('x')->setCookie('s', 'v')->setCookie('s', null);

        $this->assertNull($response->getCookie('s'));
        $this->assertSame([], $response->getCookies());
    }

    public function testCookieOptionsPassedToSetcookie(): void {
        // send() can't be observed in CLI (headers_list() is always empty), so test the options it passes
        $response = Response::text('x')->setCookie('s', 'v', 60, '/app', 'example.com', true, false, 'strict');

        $cookie = $response->getCookie('s');
        $this->assertIsArray($cookie);

        $options = (new ReflectionMethod(Response::class, 'cookieOptions'))->invoke(null, $cookie);

        $this->assertSame([
            'expires'  => $cookie['expires'],
            'path'     => '/app',
            'domain'   => 'example.com',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'Strict',
        ], $options);

        $defaults = Response::text('x')->setCookie('s', 'v')->getCookie('s');
        $this->assertIsArray($defaults);

        $this->assertSame([
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ], (new ReflectionMethod(Response::class, 'cookieOptions'))->invoke(null, $defaults));
    }

}
