<?php declare(strict_types=1);

namespace spl\tests\web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use spl\web\Request;

final class RequestTest extends TestCase {

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $cookie = [];

    protected function setUp(): void {
        $this->server = $_SERVER;
        $this->post   = $_POST;
        $this->cookie = $_COOKIE;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;
        $_POST   = $this->post;
        $_COOKIE = $this->cookie;
    }

    /**
     * Replaces $_SERVER with a minimal web request environment.
     *
     * @param array<string, string> $vars
     */
    private function setServer(array $vars): void {
        $_SERVER = $vars + ['REQUEST_METHOD' => 'GET'];
        $_POST   = [];
        $_COOKIE = [];
    }

    public function testFromGlobalsWithoutQueryStringOrHost(): void {
        $this->setServer(['REQUEST_URI' => '/users']);

        $request = Request::fromGlobals();

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/users', $request->getPath());
        $this->assertSame('', $request->getHost());
        $this->assertNull($request->lookup('page'));
    }

    public function testFromGlobalsParsesQueryString(): void {
        $this->setServer([
            'REQUEST_URI'  => '/search?a=1&b[]=2',
            'QUERY_STRING' => 'a=1&b[]=2',
            'HTTP_HOST'    => 'example.com',
        ]);

        $request = Request::fromGlobals();

        $this->assertSame('/search', $request->getPath());
        $this->assertSame('1', $request->lookup('a'));
        $this->assertSame(['2'], $request->lookup('b'));
    }

    public function testFromGlobalsHostFallsBackToServerName(): void {
        $this->setServer(['REQUEST_URI' => '/', 'SERVER_NAME' => 'server.local']);
        $this->assertSame('server.local', Request::fromGlobals()->getHost());

        $this->setServer(['REQUEST_URI' => '/', 'SERVER_NAME' => 'server.local', 'HTTP_HOST' => 'example.com']);
        $this->assertSame('example.com', Request::fromGlobals()->getHost());
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function pathProvider(): array {
        return [
            'plain'             => ['/users/5', '/users/5'],
            'query'             => ['/users/5?x=1', '/users/5'],
            'empty query'       => ['/a?', '/a'],
            'fragment'          => ['/a#top', '/a'],
            'colon'             => ['/p/12:30', '/p/12:30'],
            'colon and query'   => ['/a:1?x=/b:c', '/a:1'],
            'double slash'      => ['//x/y?z', '//x/y'],
            'encoded'           => ['/a%20b', '/a%20b'],
            'root'              => ['/?q=1', '/'],
            'absolute form'     => ['http://example.com/p?q', '/p'],
            'missing'           => [null, '/'],
            'empty'             => ['', '/'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testFromGlobalsPath(?string $uri, string $expected): void {
        $this->setServer($uri === null ? [] : ['REQUEST_URI' => $uri]);

        $this->assertSame($expected, Request::fromGlobals()->getPath());
    }

    public function testFromGlobalsReadsHeadersPostAndCookies(): void {
        $this->setServer([
            'REQUEST_METHOD'  => 'POST',
            'REQUEST_URI'     => '/form',
            'HTTP_HOST'       => 'example.com',
            'HTTP_X_API_KEY'  => 'k',
        ]);
        $_POST   = ['name' => 'Ann'];
        $_COOKIE = ['session' => 'abc'];

        $request = Request::fromGlobals();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('k', $request->getHeader('x-api-key'));
        $this->assertSame(['name' => 'Ann'], $request->getBody());
        $this->assertSame('abc', $request->getCookie('session'));
    }

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
