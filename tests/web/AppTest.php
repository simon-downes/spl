<?php declare(strict_types=1);

namespace spl\tests\web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use spl\tests\web\fixtures\TestableApp;
use spl\web\App;
use spl\web\Request;
use spl\web\Response;
use spl\web\WebException;

final class AppTest extends TestCase {

    protected function setUp(): void {
        TestableApp::reset();
    }

    protected function tearDown(): void {
        TestableApp::reset();
    }

    private function request(string $method, string $path): Request {
        return new Request($method, 'example.com', $path);
    }

    private function assertMethodNotAllowed(string $path, string $method): void {
        try {
            TestableApp::matchRoute($path, $method);
            $this->fail("Expected 405 for {$method} {$path}");
        }
        catch (WebException $e) {
            $this->assertSame(405, $e->getCode());
        }
    }

    // --- method prefixes ---------------------------------------------------------------------

    public function testSingleMethodPrefix(): void {
        $handler = fn(Request $r) => 'x';
        TestableApp::add(['GET:/x' => $handler]);

        $this->assertSame([$handler, []], TestableApp::matchRoute('/x', 'GET'));
        $this->assertMethodNotAllowed('/x', 'POST');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multiMethodKeys(): array {
        return [
            'bare'      => ['GET|POST:/x'],
            'bracketed' => ['(GET|POST):/x'],
        ];
    }

    #[DataProvider('multiMethodKeys')]
    public function testMultiMethodPrefix(string $key): void {
        $handler = fn(Request $r) => 'x';
        TestableApp::add([$key => $handler]);

        $this->assertSame(['/x' => ['GET' => $handler, 'POST' => $handler]], TestableApp::routes());
        $this->assertSame($handler, TestableApp::matchRoute('/x', 'GET')[0]);
        $this->assertSame($handler, TestableApp::matchRoute('/x', 'POST')[0]);
        $this->assertMethodNotAllowed('/x', 'PUT');
    }

    public function testBracketedPostRouteFromDocsMatches(): void {
        TestableApp::add(['(POST):/api/users' => fn(Request $r) => ['ok' => true]]);

        $response = TestableApp::dispatchRequest($this->request('POST', '/api/users'));

        $this->assertSame('{"ok":true}', $response->getBody());
    }

    public function testPathMayContainColons(): void {
        TestableApp::add(['GET:/a:b' => fn(Request $r) => 'x']);

        $this->assertSame(['/a:b'], array_keys(TestableApp::routes()));
    }

    public function testSamePathWithDifferentMethodsIsMerged(): void {
        $get  = fn(Request $r) => 'get';
        $post = fn(Request $r) => 'post';
        TestableApp::add(['GET:/x' => $get, 'POST:/x' => $post]);

        $this->assertSame(['/x' => ['GET' => $get, 'POST' => $post]], TestableApp::routes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRouteKeys(): array {
        return [
            'no prefix'          => ['/x'],
            'no prefix regex'    => ['/users/(\d+)'],
            'lowercase'          => ['get:/x'],
            'bracket char'       => ['[A:/x'],
            'underscore'         => ['_:/x'],
            'caret'              => ['^:/x'],
            'empty segment'      => ['GET|:/x'],
            'unbalanced bracket' => ['(GET:/x'],
            'spaces'             => ['GET | POST:/x'],
        ];
    }

    #[DataProvider('invalidRouteKeys')]
    public function testRouteWithoutValidMethodPrefixThrows(string $key): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\"{$key}\"");

        TestableApp::add([$key => fn(Request $r) => 'x']);
    }

    // --- handle() end to end -----------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testHandleRoutesRequestFromRoutesFile(): void {
        define('SPL_ROOT', __DIR__ . '/fixtures/app');

        $this->expectOutputString('{"method":"POST"}');

        App::handle($this->request('POST', '/items'));
    }

    // --- handler return values ---------------------------------------------------------------

    public function testUnsupportedReturnTypeThrows(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => 42]);

        try {
            TestableApp::dispatchRequest($this->request('GET', '/x'));
            $this->fail('Expected UnexpectedValueException');
        }
        catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('GET /x', $e->getMessage());
            $this->assertStringContainsString('int', $e->getMessage());
            $this->assertTrue(Response::fromThrowable($e)->isServerError());
        }
    }

}
