<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\TestCase;
use spl\Log;

/**
 * Log is used here without SPL::init(), so these tests also cover the no-init fallbacks.
 */
final class LogTest extends TestCase {

    private string $dir;

    private string|false $error_log;

    protected function setUp(): void {
        $dir = tempnam(sys_get_temp_dir(), 'spl-log-');
        $this->assertIsString($dir);
        unlink($dir);
        mkdir($dir);
        $this->dir = $dir;

        $this->error_log = ini_get('error_log');
    }

    protected function tearDown(): void {
        Log::setFile(null);
        unset($_ENV['APP_LOG_FILE'], $_ENV['APP_LOG_LEVEL']);
        ini_set('error_log', (string) $this->error_log);

        foreach (['a/b/app.log', 'a/b', 'a', 'env.log', 'set.log', 'explicit.log', 'php.log'] as $item) {
            $path = "{$this->dir}/{$item}";
            is_dir($path) ? rmdir($path) : (file_exists($path) && unlink($path));
        }
        rmdir($this->dir);
    }

    public function testWritesToAppLogFileWithoutInit(): void {
        $_ENV['APP_LOG_FILE'] = "{$this->dir}/env.log";

        Log::info("first\nsecond");

        $this->assertSame(
            [SPL_REQUEST_ID . ' [INFO] first', SPL_REQUEST_ID . ' [INFO] second'],
            $this->readLines('env.log'),
        );
    }

    public function testFallsBackToErrorLogWithoutDestination(): void {
        ini_set('error_log', "{$this->dir}/php.log");

        Log::warning('to error_log');

        $this->assertStringContainsString(SPL_REQUEST_ID . ' [WARNING] to error_log', $this->read('php.log'));
    }

    public function testSetFileOverridesAppLogFile(): void {
        $_ENV['APP_LOG_FILE'] = "{$this->dir}/env.log";
        Log::setFile("{$this->dir}/set.log");

        Log::error('to set file');

        $this->assertStringContainsString('[ERROR] to set file', $this->read('set.log'));
        $this->assertFileDoesNotExist("{$this->dir}/env.log");
    }

    public function testSetFileNullRestoresAppLogFile(): void {
        $_ENV['APP_LOG_FILE'] = "{$this->dir}/env.log";
        Log::setFile("{$this->dir}/set.log");
        Log::setFile(null);

        Log::info('back to env');

        $this->assertStringContainsString('[INFO] back to env', $this->read('env.log'));
    }

    public function testSetFilePhpUsesErrorLog(): void {
        $_ENV['APP_LOG_FILE'] = "{$this->dir}/env.log";
        ini_set('error_log', "{$this->dir}/php.log");
        Log::setFile('php');

        Log::info('via php');

        $this->assertStringContainsString('[INFO] via php', $this->read('php.log'));
        $this->assertFileDoesNotExist("{$this->dir}/env.log");
    }

    public function testExplicitFileOverridesSetFile(): void {
        Log::setFile("{$this->dir}/set.log");

        Log::info('explicit', "{$this->dir}/explicit.log");

        $this->assertStringContainsString('[INFO] explicit', $this->read('explicit.log'));
        $this->assertFileDoesNotExist("{$this->dir}/set.log");
    }

    public function testCreatesMissingDirectory(): void {
        Log::setFile("{$this->dir}/a/b/app.log");

        Log::info('nested');

        $this->assertStringContainsString('[INFO] nested', $this->read('a/b/app.log'));
    }

    public function testUsesErrorLogReflectsDestinationAndLevel(): void {
        $this->assertTrue(Log::usesErrorLog('ERROR'));

        Log::setFile('php');
        $this->assertTrue(Log::usesErrorLog('error'));

        Log::setFile("{$this->dir}/set.log");
        $this->assertFalse(Log::usesErrorLog('ERROR'));

        Log::setFile(null);
        $_ENV['APP_LOG_FILE'] = "{$this->dir}/env.log";
        $this->assertFalse(Log::usesErrorLog('ERROR'));

        unset($_ENV['APP_LOG_FILE']);
        $_ENV['APP_LOG_LEVEL'] = 'critical';
        $this->assertFalse(Log::usesErrorLog('ERROR'));
        $this->assertTrue(Log::usesErrorLog('CRITICAL'));
    }

    private function read(string $file): string {
        $content = file_get_contents("{$this->dir}/{$file}");
        $this->assertIsString($content);
        return $content;
    }

    /**
     * @return list<string> Lines without the leading date and time
     */
    private function readLines(string $file): array {
        $lines = explode("\n", trim($this->read($file)));
        return array_map(fn(string $line): string => substr($line, 20), $lines);
    }

}
