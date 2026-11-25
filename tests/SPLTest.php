<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for SPL::init() and its error handling.
 *
 * SPL::init() defines process-wide constants and SPL::error() exits, so each test runs a fixture
 * script in its own PHP process, inside a temporary application root, and inspects the exit code,
 * output and log files.
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

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('debugProvider')]
    public function testLogWriteFailureStillReportsTheError(array $env): void {
        // a regular file where the log directory should be, so the log can't be written
        touch("{$this->root}/blocker");

        $result = $this->runFixture(self::CAUGHT_ERROR, $env + ['APP_LOG_FILE' => 'blocker/app.log']);

        $this->assertSame(1, $result['exit'], $result['stderr']);
        $this->assertSame(1, substr_count($result['stderr'], self::MESSAGE), $result['stderr']);
        $this->assertStringContainsString('blocker/app.log', $result['stderr']);
        $this->assertStringNotContainsString('PHP Fatal error', $result['stderr']);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function debugProvider(): array {
        return [
            'debug'    => [['APP_DEBUG' => 'true']],
            'no debug' => [['APP_DEBUG' => 'false']],
        ];
    }

    /**
     * Runs a fixture script in a separate PHP process.
     *
     * The script lives in the temporary application root, with the given variables in its .env
     * file, and runs with a different working directory so relative paths can be checked.
     *
     * @param array<string, string> $env
     * @param list<string> $ini
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runFixture(string $code, array $env = [], array $ini = []): array {
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

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
