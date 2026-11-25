<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use ErrorException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use spl\SPL;

/**
 * End-to-end tests for SPL::init() and its error handling.
 *
 * SPL::init() defines process-wide constants and SPL::error() exits, so most tests run a fixture
 * script in its own PHP process, inside a temporary application root, and inspect the exit code,
 * output and log files. The handleError() tests call the handler directly and need no init().
 */
final class SPLTest extends TestCase {

    private const string MESSAGE = 'spl-test-boom';

    /**
     * Prints the request ID, then reports an exception through SPL::error().
     */
    private const string CAUGHT_ERROR = <<<'PHP'
        SPL::init(__DIR__);
        echo SPL_REQUEST_ID;
        try {
            throw new RuntimeException('spl-test-boom');
        }
        catch (Throwable $e) {
            SPL::error($e);
        }
        PHP;

    private string $root;

    private string $cwd;

    protected function setUp(): void {
        $this->root = $this->makeTempDir('spl-root-');
        $this->cwd  = $this->makeTempDir('spl-cwd-');
    }

    protected function tearDown(): void {
        $this->removeDir($this->root);
        $this->removeDir($this->cwd);
    }

    public function testRelativeLogFileResolvesAgainstRootAndCreatesDirectory(): void {
        $result = $this->runFixture(
            'SPL::init(__DIR__); echo SPL_REQUEST_ID; Log::info("hello");',
            ['APP_LOG_FILE' => 'logs/app.log'],
        );

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertFileExists("{$this->root}/logs/app.log");
        $this->assertStringContainsString("{$result['stdout']} [INFO] hello", $this->readLog('logs/app.log'));
        $this->assertSame([], $this->listDir($this->cwd));
    }

    public function testAbsoluteLogFileIsUsedAsIs(): void {
        $file = "{$this->cwd}/abs/app.log";

        $result = $this->runFixture('SPL::init(__DIR__); Log::info("hello");', ['APP_LOG_FILE' => $file]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('[INFO] hello', (string) file_get_contents($file));
    }

    /**
     * Windows absolute paths are kept as they are. On Linux they are plain relative names, so the
     * file lands in the fixture's working directory rather than under the root.
     */
    #[DataProvider('windowsPathProvider')]
    public function testWindowsAbsoluteLogFileIsNotResolvedAgainstRoot(string $file): void {
        $result = $this->runFixture('SPL::init(__DIR__); Log::info("hello");', ['APP_LOG_FILE' => $file]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame(['.env', 'run.php'], $this->listDir($this->root));
        $this->assertNotSame([], $this->listDir($this->cwd));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function windowsPathProvider(): array {
        return [
            'drive, backslash'     => ['C:\\logs\\app.log'],
            'drive, forward slash' => ['c:/logs/app.log'],
            'UNC'                  => ['\\\\server\\share\\app.log'],
        ];
    }

    public function testPhpLogFileUsesErrorLog(): void {
        $result = $this->runFixture('SPL::init(__DIR__); Log::info("hello");', ['APP_LOG_FILE' => 'php']);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('[INFO] hello', $result['stderr']);
        $this->assertFileDoesNotExist("{$this->root}/php");
    }

    public function testCliErrorWritesTraceToStderrWhenLoggingToFile(): void {
        $result = $this->runFixture(self::CAUGHT_ERROR, ['APP_LOG_FILE' => 'logs/app.log']);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('RuntimeException {', $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE));

        $log = $this->readLog('logs/app.log');
        $this->assertStringContainsString("{$result['stdout']} [ERROR] RuntimeException {", $log);
        $this->assertSame(1, substr_count($log, self::MESSAGE));
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('terminalLogProvider')]
    public function testCliErrorIsPrintedOnceWhenLogIsTheTerminal(array $env): void {
        $result = $this->runFixture(self::CAUGHT_ERROR, $env);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function terminalLogProvider(): array {
        return [
            'no log file'                 => [[]],
            'php log file'                => [['APP_LOG_FILE' => 'php']],
            'error level not logged'      => [['APP_LOG_LEVEL' => 'critical']],
            'php log file, level ignored' => [['APP_LOG_FILE' => 'php', 'APP_LOG_LEVEL' => 'critical']],
        ];
    }

    public function testCliErrorIsPrintedWhenErrorLogIsAFile(): void {
        $result = $this->runFixture(self::CAUGHT_ERROR, [], ["error_log={$this->root}/php.log"]);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
        $this->assertStringContainsString(self::MESSAGE, $this->readLog('php.log'));
    }

    public function testUncaughtExceptionAfterInitIsHandled(): void {
        $result = $this->runFixture(
            "SPL::init(__DIR__);\necho SPL_REQUEST_ID;\nthrow new RuntimeException('spl-test-boom');",
            ['APP_LOG_FILE' => 'logs/app.log'],
        );

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
        $this->assertStringNotContainsString('PHP Fatal error', $result['stderr']);

        $log = $this->readLog('logs/app.log');
        $this->assertStringContainsString("{$result['stdout']} [ERROR] RuntimeException {", $log);
        $this->assertSame(1, substr_count($log, self::MESSAGE));
    }

    public function testDebugConvertsWarningsToExceptions(): void {
        $result = $this->runFixture('SPL::init(__DIR__); echo $undefined; echo "after";', ['APP_DEBUG' => 'true']);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('ErrorException {', $result['stderr']);
        $this->assertStringContainsString('WARNING', $result['stderr']);
        $this->assertStringContainsString('Undefined variable $undefined', $result['stderr']);
        $this->assertStringNotContainsString('after', $result['stdout']);
    }

    public function testDebugLeavesSuppressedWarningsAlone(): void {
        $result = $this->runFixture('SPL::init(__DIR__); echo @$undefined; echo "after";', ['APP_DEBUG' => 'true']);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('after', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testNonDebugLeavesWarningsToPhp(): void {
        $result = $this->runFixture('SPL::init(__DIR__); echo $undefined; echo "after";', ['APP_DEBUG' => 'false']);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('after', $result['stdout']);
        $this->assertStringContainsString('PHP Warning:  Undefined variable $undefined', $result['stderr']);
        $this->assertStringNotContainsString('ErrorException', $result['stderr']);
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('fatalErrorProvider')]
    public function testFatalErrorIsHandledAtShutdown(string $code, string $message, array $env): void {
        $result = $this->runFixture("SPL::init(__DIR__);\necho SPL_REQUEST_ID;\n{$code}", $env + ['APP_LOG_FILE' => 'logs/app.log']);

        // PHP's own status for a fatal error
        $this->assertSame(255, $result['exit'], $result['stderr']);

        $this->assertStringContainsString('ErrorException {', $result['stderr']);
        $this->assertStringContainsString('"ERROR"', $result['stderr']);
        $this->assertStringContainsString($message, $result['stderr']);

        $log = $this->readLog('logs/app.log');
        $this->assertStringContainsString("{$result['stdout']} [ERROR] ErrorException {", $log);
        $this->assertSame(1, substr_count($log, $message), $log);
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function fatalErrorProvider(): array {
        // a linked list of small objects, so memory runs out on a small allocation
        $out_of_memory = 'ini_set("memory_limit", "16M"); $a = null; while (true) { $o = new stdClass(); $o->next = $a; $a = $o; }';
        $time_limit    = 'set_time_limit(1); while (true) {}';

        return [
            'out of memory'          => [$out_of_memory, 'Allowed memory size', []],
            'out of memory in debug' => [$out_of_memory, 'Allowed memory size', ['APP_DEBUG' => 'true']],
            'time limit'             => [$time_limit, 'Maximum execution time', []],
        ];
    }

    public function testDebugWebRequestWithoutQueryStringIsNotAnError(): void {
        // php -S leaves QUERY_STRING unset when the URL has no query string
        $code = <<<'PHP'
            SPL::init(__DIR__);
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/path'];
            echo \spl\web\Request::fromGlobals()->getPath();
            PHP;

        $result = $this->runFixture($code, ['APP_DEBUG' => 'true']);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('/path', $result['stdout']);
    }

    public function testHandleErrorThrowsErrorException(): void {
        $level = error_reporting(E_ALL);

        try {
            SPL::handleError(E_WARNING, 'msg', 'f.php', 7);
            $this->fail('No exception thrown');
        }
        catch (ErrorException $e) {
            $this->assertSame('msg', $e->getMessage());
            $this->assertSame(E_WARNING, $e->getSeverity());
            $this->assertSame('f.php', $e->getFile());
            $this->assertSame(7, $e->getLine());
        }
        finally {
            error_reporting($level);
        }
    }

    public function testHandleErrorIgnoresUnreportedErrors(): void {
        $level = error_reporting(E_ALL & ~E_WARNING);

        try {
            $this->assertFalse(SPL::handleError(E_WARNING, 'msg', 'f.php', 7));
        }
        finally {
            error_reporting($level);
        }
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('debugProvider')]
    public function testLogWriteFailureStillReportsTheError(array $env, string $failure): void {
        // a directory where the log file should be, so the log can't be written
        mkdir("{$this->root}/app.log");

        $result = $this->runFixture(self::CAUGHT_ERROR, $env + ['APP_LOG_FILE' => 'app.log']);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
        $this->assertStringContainsString("{$failure} file_put_contents({$this->root}/app.log)", $result['stderr']);
        $this->assertStringNotContainsString('PHP Fatal error', $result['stderr']);
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('debugProvider')]
    public function testUncreatableLogDirectoryReportsTheRealCause(array $env, string $failure): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to a read-only directory');
        }

        mkdir("{$this->root}/ro", 0555);

        try {
            $result = $this->runFixture(self::CAUGHT_ERROR, $env + ['APP_LOG_FILE' => 'ro/sub/app.log']);
        }
        finally {
            chmod("{$this->root}/ro", 0755);
        }

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
        $this->assertStringContainsString("{$failure} Unable to create log directory {$this->root}/ro/sub: mkdir(): Permission denied", $result['stderr']);
        $this->assertStringNotContainsString('No such file or directory', $result['stderr']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function debugProvider(): array {
        return [
            // the warning becomes an exception, so SPL::error() falls back to error_log()
            'debug'    => [['APP_DEBUG' => 'true'], 'Unable to write log:'],
            // PHP reports the warning itself
            'no debug' => [['APP_DEBUG' => 'false'], 'PHP Warning: '],
        ];
    }

    public function testFixtureOutputLargerThanAPipeBufferIsRead(): void {
        // stderr fills its pipe before anything is written to stdout
        $result = $this->runFixture('fwrite(STDERR, str_repeat("e", 200000)); echo str_repeat("o", 200000);');

        $this->assertSame(0, $result['exit']);
        $this->assertSame(200000, strlen($result['stdout']));
        $this->assertSame(200000, strlen($result['stderr']));
    }

    public function testFixtureThatNeverFinishesFailsTheTest(): void {
        try {
            $this->runFixture('echo "started"; while (true) { usleep(100000); }', timeout: 1);
        }
        catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Fixture did not finish within 1 s', $e->getMessage());
            $this->assertStringContainsString('started', $e->getMessage());
            return;
        }

        $this->fail('The fixture was not stopped');
    }

    /**
     * Runs a fixture script in a separate PHP process.
     *
     * The script lives in the temporary application root, with the given variables in its .env
     * file, and runs with a different working directory so relative paths can be checked.
     *
     * @param array<string, string> $env
     * @param list<string> $ini
     * @param int $timeout Seconds before the fixture is killed and the test fails
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runFixture(string $code, array $env = [], array $ini = [], int $timeout = 30): array {
        $lines = [];
        foreach ($env as $k => $v) {
            $lines[] = "{$k}={$v}";
        }
        file_put_contents("{$this->root}/.env", implode("\n", $lines) . "\n");

        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents("{$this->root}/run.php", "<?php declare(strict_types=1);\nrequire {$autoload};\nuse spl\\SPL;\nuse spl\\Log;\n{$code}\n");

        // a known baseline: PHP's own error display off, its error log on and sent to stderr
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log='];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = "{$this->root}/run.php";

        // don't let the test runner's own APP_* variables leak into the fixture
        $process_env = array_filter(
            getenv(),
            fn(string $k): bool => !str_starts_with($k, 'APP_') && !str_starts_with($k, 'DB_'),
            ARRAY_FILTER_USE_KEY,
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd, $process_env);
        $this->assertIsResource($process);

        // read both pipes as data arrives, so a fixture blocked on a full stderr pipe can't deadlock
        // us while we wait for stdout, and give up at the deadline so a stuck fixture can't hang
        $open   = [1 => $pipes[1], 2 => $pipes[2]];
        $output = [1 => '', 2 => ''];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $deadline = microtime(true) + $timeout;

        while ($open !== []) {

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                proc_terminate($process, 9);
                foreach ($open as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                $this->fail("Fixture did not finish within {$timeout} s\nstdout: {$output[1]}\nstderr: {$output[2]}");
            }

            $read   = array_values($open);
            $write  = null;
            $except = null;

            if (stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000)) === false) {
                $this->fail('stream_select() failed while reading the fixture output');
            }

            foreach ($open as $fd => $pipe) {
                if (!in_array($pipe, $read, true)) {
                    continue;
                }
                $output[$fd] .= (string) fread($pipe, 65536);
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$fd]);
                }
            }

        }

        return ['exit' => proc_close($process), 'stdout' => $output[1], 'stderr' => $output[2]];
    }

    private function readLog(string $path): string {
        $content = file_get_contents("{$this->root}/{$path}");
        $this->assertIsString($content);
        return $content;
    }

    /**
     * @return list<string>
     */
    private function listDir(string $dir): array {
        return array_values(array_diff((array) scandir($dir), ['.', '..']));
    }

    private function makeTempDir(string $prefix): string {
        $dir = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($dir);
        unlink($dir);
        mkdir($dir);
        return $dir;
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach ($this->listDir($dir) as $item) {
            $path = "{$dir}/{$item}";
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

}
