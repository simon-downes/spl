<?php declare(strict_types=1);

namespace spl\tests\web;

use Error;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use spl\tests\web\fixtures\ShowUser;
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
            TestableApp::dispatchRequest($this->request($method, $path));
            $this->fail("Expected 405 for {$method} {$path}");
        }
        catch (WebException $e) {
            $this->assertSame(405, $e->getCode());
        }
    }

    // --- method prefixes ---------------------------------------------------------------------

    public function testSingleMethodPrefix(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => 'x']);

        $this->assertSame('x', TestableApp::dispatchRequest($this->request('GET', '/x'))->getBody());
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
        TestableApp::add([$key => fn(Request $r) => $r->getMethod()]);

        $this->assertSame('GET', TestableApp::dispatchRequest($this->request('GET', '/x'))->getBody());
        $this->assertSame('POST', TestableApp::dispatchRequest($this->request('POST', '/x'))->getBody());
        $this->assertMethodNotAllowed('/x', 'PUT');
    }

    public function testBracketedPostRouteFromDocsMatches(): void {
        TestableApp::add(['(POST):/api/users' => fn(Request $r) => ['ok' => true]]);

        $response = TestableApp::dispatchRequest($this->request('POST', '/api/users'));

        $this->assertSame('{"ok":true}', $response->getBody());
    }

    public function testPathMayContainColons(): void {
        TestableApp::add(['GET:/a:b' => fn(Request $r) => 'x']);

        $this->assertSame('x', TestableApp::dispatchRequest($this->request('GET', '/a:b'))->getBody());
    }

    public function testSamePathWithDifferentMethodsIsMerged(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => 'get', 'POST:/x' => fn(Request $r) => 'post']);

        $this->assertSame('get', TestableApp::dispatchRequest($this->request('GET', '/x'))->getBody());
        $this->assertSame('post', TestableApp::dispatchRequest($this->request('POST', '/x'))->getBody());
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

    // --- route parameters --------------------------------------------------------------------

    public function testUnnamedGroupsArePassedPositionally(): void {
        TestableApp::add(['GET:/users/(\d+)/(\w+)' => fn(Request $r, string $id, string $tab) => "{$id}:{$tab}"]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/users/42/posts'));

        $this->assertSame('42:posts', $response->getBody());
    }

    public function testNamedGroupIsPassedAsNamedArgument(): void {
        TestableApp::add(['GET:/users/(?<id>\d+)' => fn(Request $r, string $id) => "user {$id}"]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/users/42'));

        $this->assertSame('user 42', $response->getBody());
    }

    public function testInvokableClassHandlerGetsNamedArgument(): void {
        TestableApp::add(['GET:/users/(?<id>\d+)' => ShowUser::class]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/users/42'));

        $this->assertSame('user 42', $response->getBody());
    }

    public function testNamedGroupsAreMatchedByNameNotPosition(): void {
        TestableApp::add(['GET:/p/(?<b>\w+)/(?<a>\w+)' => fn(Request $r, string $a, string $b) => "a={$a} b={$b}"]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/p/second/first'));

        $this->assertSame('a=first b=second', $response->getBody());
    }

    public function testOnlyNamedGroupsArePassedWhenMixed(): void {
        TestableApp::add(['GET:/(\w+)/(?<id>\d+)' => fn(Request $r, string ...$args) => json_encode($args, JSON_THROW_ON_ERROR)]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/users/42'));

        $this->assertSame('{"id":"42"}', $response->getBody());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function optionalNamedGroupPaths(): array {
        return [
            'group matched'     => ['/users/42', '{"id":"42"}'],
            'group not matched' => ['/users', '[]'],
        ];
    }

    #[DataProvider('optionalNamedGroupPaths')]
    public function testUnmatchedOptionalNamedGroupIsLeftOut(string $path, string $json): void {
        TestableApp::add(['GET:/(\w+)(?:/(?<id>\d+))?' => fn(Request $r, string ...$args) => json_encode($args, JSON_THROW_ON_ERROR)]);

        $response = TestableApp::dispatchRequest($this->request('GET', $path));

        $this->assertSame($json, $response->getBody());
    }

    public function testUnmatchedNamedGroupUsesHandlerDefault(): void {
        TestableApp::add(['GET:/p(?:/(?<a>\d+))?/(?<b>\w+)' => fn(Request $r, string $b, string $a = 'none') => "a={$a} b={$b}"]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/p/x'));

        $this->assertSame('a=none b=x', $response->getBody());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function optionalUnnamedGroupPaths(): array {
        return [
            'middle group not matched'   => ['/q/x', '["","x"]'],
            'trailing group not matched' => ['/r', '[]'],
        ];
    }

    #[DataProvider('optionalUnnamedGroupPaths')]
    public function testUnmatchedUnnamedGroupsKeepPcreDefaults(string $path, string $json): void {
        TestableApp::add([
            'GET:/q(?:/(\d+))?/(\w+)' => fn(Request $r, string ...$args) => json_encode($args, JSON_THROW_ON_ERROR),
            'GET:/r(?:/(\d+))?'       => fn(Request $r, string ...$args) => json_encode($args, JSON_THROW_ON_ERROR),
        ]);

        $response = TestableApp::dispatchRequest($this->request('GET', $path));

        $this->assertSame($json, $response->getBody());
    }

    public function testNamedGroupWithoutMatchingParameterSurfacesPhpError(): void {
        TestableApp::add(['GET:/users/(?<user_id>\d+)' => fn(Request $r, string $id) => $id]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $user_id');

        TestableApp::dispatchRequest($this->request('GET', '/users/42'));
    }

    // --- HEAD --------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function getRouteKeys(): array {
        return [
            'GET only'     => ['GET:/x'],
            'GET and HEAD' => ['GET|HEAD:/x'],
        ];
    }

    #[DataProvider('getRouteKeys')]
    public function testHeadIsAnsweredLikeGet(string $key): void {
        TestableApp::add([$key => fn(Request $r) => '<p>hello</p>']);

        $get  = TestableApp::dispatchRequest($this->request('GET', '/x'));
        $head = TestableApp::dispatchRequest($this->request('HEAD', '/x'));

        $this->assertSame($get->getStatus(), $head->getStatus());
        $this->assertSame($get->getHeaders(), $head->getHeaders());
        // compared as a string so the assertion doesn't depend on the header value's type
        $this->assertSame('12', (string) $head->getHeader('content-length'));
    }

    public function testHeadIsNotAllowedWithoutGet(): void {
        TestableApp::add(['POST:/x' => fn(Request $r) => 'x']);

        $this->assertMethodNotAllowed('/x', 'HEAD');
    }

    public function testExplicitHeadHandlerWins(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => 'get', 'HEAD:/x' => fn(Request $r) => 'head']);

        $this->assertSame('head', TestableApp::dispatchRequest($this->request('HEAD', '/x'))->getBody());
    }

    public function testRespondSendsBodyForGet(): void {
        $this->expectOutputString('hello');

        TestableApp::respondTo($this->request('GET', '/x'), Response::html('hello'));
    }

    public function testRespondOmitsBodyForHead(): void {
        $this->expectOutputString('');

        TestableApp::respondTo($this->request('HEAD', '/x'), Response::html('hello'));
    }

    #[RunInSeparateProcess]
    public function testHandleSendsNoBodyForHead(): void {
        define('SPL_ROOT', __DIR__ . '/fixtures/app');

        $this->expectOutputString('');

        App::handle($this->request('HEAD', '/'));
    }

    #[RunInSeparateProcess]
    public function testHandleSendsBodyForGet(): void {
        define('SPL_ROOT', __DIR__ . '/fixtures/app');

        $this->expectOutputString('<h1>home</h1>');

        App::handle($this->request('GET', '/'));
    }

    // --- handler return values ---------------------------------------------------------------

    public function testStringReturnBecomesHtml(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => '<p>hi</p>']);

        $response = TestableApp::dispatchRequest($this->request('GET', '/x'));

        $this->assertSame(200, $response->getStatus()['code']);
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('content-type'));
        $this->assertSame('<p>hi</p>', $response->getBody());
    }

    public function testArrayReturnBecomesJson(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => ['a' => 1]]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/x'));

        $this->assertSame(200, $response->getStatus()['code']);
        $this->assertSame('application/json; charset=UTF-8', $response->getHeader('content-type'));
        $this->assertSame('{"a":1}', $response->getBody());
    }

    public function testResponseReturnIsUsedAsIs(): void {
        $created = Response::text('made', 201);
        TestableApp::add(['GET:/x' => fn(Request $r) => $created]);

        $this->assertSame($created, TestableApp::dispatchRequest($this->request('GET', '/x')));
    }

    public function testNullReturnBecomesNoContent(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => null]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/x'));

        $this->assertSame(['code' => 204, 'message' => 'No Content'], $response->getStatus());
        $this->assertSame('', $response->getBody());
        $this->assertNull($response->getHeader('content-type'));
        $this->assertNull($response->getHeader('content-length'));
    }

    /**
     * PHP adds its default Content-Type (default_mimetype) to any response that doesn't send one;
     * the CLI SAPI has no headers to inspect, so this checks that setting instead.
     */
    #[RunInSeparateProcess]
    public function testRespondStopsPhpDefaultContentTypeForNoContent(): void {
        TestableApp::add(['GET:/x' => fn(Request $r) => null]);
        $request = $this->request('GET', '/x');

        TestableApp::respondTo($request, TestableApp::dispatchRequest($request));

        $this->assertSame('', ini_get('default_mimetype'));
    }

    #[RunInSeparateProcess]
    public function testRespondKeepsPhpDefaultContentTypeForOtherResponses(): void {
        $this->expectOutputString('hello');

        TestableApp::respondTo($this->request('GET', '/x'), Response::html('hello'));

        $this->assertSame('text/html', ini_get('default_mimetype'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function jsonSerializableValues(): array {
        return [
            'object' => [['id' => 7, 'name' => 'Ann'], '{"id":7,"name":"Ann"}'],
            'scalar' => ['plain', '"plain"'],
        ];
    }

    #[DataProvider('jsonSerializableValues')]
    public function testJsonSerializableReturnBecomesJson(mixed $value, string $json): void {
        $result = new class ($value) implements JsonSerializable {
            public function __construct(private mixed $value) {}

            public function jsonSerialize(): mixed {
                return $this->value;
            }
        };
        TestableApp::add(['GET:/x' => fn(Request $r) => $result]);

        $response = TestableApp::dispatchRequest($this->request('GET', '/x'));

        $this->assertSame(200, $response->getStatus()['code']);
        $this->assertSame('application/json; charset=UTF-8', $response->getHeader('content-type'));
        $this->assertSame($json, $response->getBody());
    }

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
