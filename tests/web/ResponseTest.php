<?php declare(strict_types=1);

namespace spl\tests\web;

use PHPUnit\Framework\TestCase;
use spl\web\Response;

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

}
