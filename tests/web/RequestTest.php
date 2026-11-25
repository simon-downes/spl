<?php declare(strict_types=1);

namespace spl\tests\web;

use PHPUnit\Framework\TestCase;
use spl\web\Request;

final class RequestTest extends TestCase {

    private function makeRequest(): Request {
        return new Request(
            'POST',
            'example.com',
            '/users',
            ['page' => '2', 'name' => 'from-query'],
            ['name' => 'from-body', 'email' => 'user@example.com'],
            ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Custom-Header' => 'abc'],
            ['session' => 'xyz'],
            '203.0.113.5',
        );
    }

    public function testGetters(): void {
        $request = $this->makeRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('example.com', $request->getHost());
        $this->assertSame('/users', $request->getPath());
        $this->assertSame('203.0.113.5', $request->getSourceIP());
        $this->assertSame(['name' => 'from-body', 'email' => 'user@example.com'], $request->getBody());
    }

    public function testHeadersAreCaseInsensitive(): void {
        $request = $this->makeRequest();

        $this->assertSame('abc', $request->getHeader('x-custom-header'));
        $this->assertSame('abc', $request->getHeader('X-CUSTOM-HEADER'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeader('content-type'));
        $this->assertSame('', $request->getHeader('authorization'));
    }

    public function testCookies(): void {
        $request = $this->makeRequest();

        $this->assertSame('xyz', $request->getCookie('session'));
        $this->assertSame('', $request->getCookie('missing'));
    }

    public function testLookupChecksQueryThenBodyThenDefault(): void {
        $request = $this->makeRequest();

        $this->assertSame('2', $request->lookup('page'));
        $this->assertSame('from-query', $request->lookup('name'));
        $this->assertSame('user@example.com', $request->lookup('email'));
        $this->assertSame('default', $request->lookup('missing', 'default'));
    }

    public function testJsonBodyIsDecoded(): void {
        $request = new Request(
            'POST',
            'example.com',
            '/api/users',
            body: '{"name":"John","tags":["a","b"]}',
            headers: ['Content-Type' => 'application/json'],
        );

        $this->assertSame(['name' => 'John', 'tags' => ['a', 'b']], $request->getBody());
        $this->assertSame('John', $request->lookup('name'));
    }

    public function testAttributes(): void {
        $request = $this->makeRequest();

        $this->assertNull($request->getAttribute('user'));
        $this->assertSame('guest', $request->getAttribute('user', 'guest'));

        $request->setAttribute('user', 'alice');

        $this->assertSame('alice', $request->getAttribute('user'));
    }

}
