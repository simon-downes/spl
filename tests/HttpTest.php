<?php declare(strict_types=1);

namespace spl\tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use spl\Http;
use spl\HttpResponse;

final class HttpTest extends TestCase {

    /** @var array<string, resource> fixture server processes keyed by name */
    private static array $servers = [];

    /** @var array<string, string> base URLs of the running fixture servers */
    private static array $urls = [];

    public static function setUpBeforeClass(): void {

        $fixtures = __DIR__ . '/fixtures/http';

        $port = self::freePort();
        self::startServer('router', [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$fixtures}/router.php"], $port);

        $port = self::freePort();
        self::startServer('continue', [PHP_BINARY, "{$fixtures}/continue-server.php", (string) $port], $port);

    }

    public static function tearDownAfterClass(): void {

        foreach (self::$servers as $process) {
            proc_terminate($process);
            proc_close($process);
        }

        self::$servers = [];
        self::$urls    = [];

    }

    public static function statusLineProvider(): array {
        return [
            'http/1.1' => ['HTTP/1.1 200 OK', ['http_version' => '1.1', 'status_code' => 200, 'status_message' => 'OK']],
            'multi-word reason' => ['HTTP/1.0 404 Not Found', ['http_version' => '1.0', 'status_code' => 404, 'status_message' => 'Not Found']],
            'http/2 without reason' => ['HTTP/2 200', ['http_version' => '2', 'status_code' => 200, 'status_message' => '']],
            'trailing space, empty reason' => ["HTTP/1.1 204 \r\n", ['http_version' => '1.1', 'status_code' => 204, 'status_message' => '']],
        ];
    }

    #[DataProvider('statusLineProvider')]
    public function testParseStatusLine(string $line, array $expected): void {
        $this->assertSame($expected, Http::parse_status_line($line));
    }

    public function testParseStatusLineRejectsInvalidLine(): void {
        $this->expectException(RuntimeException::class);
        Http::parse_status_line('Content-Type: text/html');
    }

    public function testParseHeadersFromStringWithTrailingCrlf(): void {

        $parsed = Http::parse_headers("HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-A: 1\r\nx-a: 2\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n\r\n");

        $this->assertSame(
            [
                'http'         => ['http_version' => '1.1', 'status_code' => 200, 'status_message' => 'OK'],
                'content-type' => 'text/html',
                'x-a'          => '1,2',
                'set-cookie'   => ['a=1', 'b=2'],
            ],
            $parsed,
        );

    }

    public function testParseHeadersSkipsLinesWithoutColon(): void {
        $this->assertSame(['a' => 'b'], Http::parse_headers(['A: b', 'garbage']));
    }

    public function testGetReturnsTypedResponseWithRawBody(): void {

        $response = Http::get(self::url('router', '/json'));

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame('1.1', $response->http_version);
        $this->assertSame(200, $response->status_code);
        $this->assertSame('OK', $response->status_message);
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('{"name":"spl","items":[1,2]}', $response->body);
        $this->assertSame(['name' => 'spl', 'items' => [1, 2]], $response->json());
        $this->assertTrue($response->ok());

    }

    public function testResponseHeadersAreNormalised(): void {

        $headers = Http::get(self::url('router', '/headers'))->headers;

        $this->assertSame('hello', $headers['x-custom']);
        $this->assertSame('one,two', $headers['x-multi']);
        $this->assertSame(['a=1', 'b=2'], $headers['set-cookie']);
        $this->assertArrayNotHasKey('http', $headers);

    }

    public function testEmptyBodyResponse(): void {

        $response = Http::get(self::url('router', '/empty'));

        $this->assertSame(204, $response->status_code);
        $this->assertSame('', $response->body);

    }

    public function testHeadReturnsHeadersWithoutBody(): void {

        $response = Http::head(self::url('router', '/json'));

        $this->assertSame(200, $response->status_code);
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('', $response->body);

    }

    public function testInterimContinueResponseIsDiscarded(): void {

        // libcurl sends "Expect: 100-continue" for bodies over 1 MiB
        $body = str_repeat('x', 2 * 1024 * 1024);

        $response = Http::post(self::url('continue', '/upload'), ['Content-Type: text/plain'], $body);

        $this->assertSame(201, $response->status_code);
        $this->assertSame('Created', $response->status_message);
        $this->assertSame('yes', $response->headers['x-final']);
        $this->assertSame((string) strlen($body), $response->body);

    }

    public function testPostSendsBody(): void {

        $response = Http::post(self::url('router', '/echo'), ['Content-Type: text/plain'], 'hello');

        $this->assertSame(['method' => 'POST', 'content_type' => 'text/plain', 'body' => 'hello'], $response->json());

    }

    public function testDeleteUsesCustomMethod(): void {
        $this->assertSame('DELETE', Http::delete(self::url('router', '/echo'))->json()['method']);
    }

    public function testTransportFailureThrowsWithCurlErrno(): void {

        // nothing listens on a port we just released
        $port = self::freePort();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(CURLE_COULDNT_CONNECT);

        Http::get("http://127.0.0.1:{$port}/");

    }

    public function testDefaults(): void {
        $this->assertSame(['connect_timeout' => 10, 'timeout' => 30, 'follow_redirects' => false], Http::DEFAULTS);
    }

    public static function invalidOptionsProvider(): array {
        return [
            'unknown key'              => [['timout' => 5]],
            'string timeout'           => [['timeout' => '5']],
            'float timeout'            => [['timeout' => 1.5]],
            'negative timeout'         => [['timeout' => -1]],
            'string connect timeout'   => [['connect_timeout' => '5']],
            'negative connect timeout' => [['connect_timeout' => -1]],
            'non-bool redirects'       => [['follow_redirects' => 1]],
        ];
    }

    #[DataProvider('invalidOptionsProvider')]
    public function testInvalidOptionsAreRejectedBeforeRequesting(array $options): void {

        // a request would fail with RuntimeException, so this proves validation runs first
        $this->expectException(InvalidArgumentException::class);

        Http::get('http://127.0.0.1:1/', options: $options);

    }

    public function testEmptyMethodIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        Http::request(' ', 'http://127.0.0.1:1/');
    }

    public function testTimeoutOptionAbortsSlowRequest(): void {

        $url   = self::url('router', '/slow');
        $start = microtime(true);

        try {
            Http::get($url, options: ['timeout' => 1]);
            $this->fail('Expected the request to time out');
        }
        catch (RuntimeException $e) {
            $this->assertSame(CURLE_OPERATION_TIMEDOUT, $e->getCode());
            $this->assertStringStartsWith('Curl error: ', $e->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $start);

    }

    public function testRedirectsAreNotFollowedByDefault(): void {

        $response = Http::get(self::url('router', '/redirect'));

        $this->assertSame(302, $response->status_code);
        $this->assertSame('/json', $response->headers['location']);
        $this->assertSame('yes', $response->headers['x-redirect']);

    }

    public function testFollowedRedirectKeepsOnlyFinalHeaders(): void {

        $response = Http::get(self::url('router', '/redirect'), options: ['follow_redirects' => true]);

        $this->assertSame(200, $response->status_code);
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertArrayNotHasKey('location', $response->headers);
        $this->assertArrayNotHasKey('x-redirect', $response->headers);
        $this->assertSame(['name' => 'spl', 'items' => [1, 2]], $response->json());

    }

    public function testRedirectLoopStopsAtMaxRedirects(): void {

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(CURLE_TOO_MANY_REDIRECTS);

        Http::get(self::url('router', '/loop'), options: ['follow_redirects' => true]);

    }

    public function testPostFollowingSeeOtherSwitchesToGet(): void {

        $response = Http::post(self::url('router', '/see-other'), ['Content-Type: text/plain'], 'hello', ['follow_redirects' => true]);

        $this->assertSame('GET', $response->json()['method']);

    }

    /**
     * Returns the URL of a path on a running fixture server, or skips the test.
     */
    private static function url(string $server, string $path): string {

        if (!isset(self::$urls[$server])) {
            self::markTestSkipped("Fixture server '{$server}' is not running");
        }

        return self::$urls[$server] . $path;

    }

    private static function freePort(): int {

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return 0;
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);

    }

    /**
     * Starts a fixture server and waits up to 5 seconds for it to accept connections.
     *
     * @param list<string> $command
     */
    private static function startServer(string $name, array $command, int $port): void {

        if ($port === 0) {
            return;
        }

        $null    = ['file', '/dev/null', 'w'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);

        if ($process === false) {
            return;
        }

        for ($i = 0; $i < 50; $i++) {

            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($conn !== false) {
                fclose($conn);
                self::$servers[$name] = $process;
                self::$urls[$name]    = "http://127.0.0.1:{$port}";
                return;
            }

            usleep(100_000);

        }

        proc_terminate($process);
        proc_close($process);

    }

}
