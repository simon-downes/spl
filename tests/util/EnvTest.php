<?php declare(strict_types=1);

namespace spl\tests\util;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use spl\util\Env;

final class EnvTest extends TestCase {

    private string $file;

    protected function setUp(): void {
        $file = tempnam(sys_get_temp_dir(), 'spl-env-');
        $this->assertIsString($file);
        $this->file = $file;

        file_put_contents($this->file, implode("\n", [
            '# a comment',
            'SPL_TEST_STRING=hello',
            'SPL_TEST_SPACED = spaced out ',
            'SPL_TEST_TRUE=true',
            'SPL_TEST_FALSE=False',
            'SPL_TEST_NULL=null',
            'SPL_TEST_QUOTED="quoted value"',
            'SPL_TEST_REF=${SPL_TEST_STRING}-world',
            'SPL_TEST_EXISTING=from-file',
        ]) . "\n");
    }

    protected function tearDown(): void {
        unlink($this->file);

        foreach (array_keys($_ENV) as $k) {
            if (str_starts_with((string) $k, 'SPL_TEST_')) {
                unset($_ENV[$k]);
            }
        }
    }

    public function testLoadAddsValuesToEnvironment(): void {
        Env::load($this->file);

        $this->assertSame('hello', Env::get('SPL_TEST_STRING'));
        $this->assertSame('spaced out', Env::get('SPL_TEST_SPACED'));
        $this->assertSame('hello-world', Env::get('SPL_TEST_REF'));
        $this->assertArrayNotHasKey('# a comment', Env::all());
    }

    public function testGetConvertsTypedStrings(): void {
        Env::load($this->file);

        $this->assertTrue(Env::get('SPL_TEST_TRUE'));
        $this->assertFalse(Env::get('SPL_TEST_FALSE'));
        $this->assertNull(Env::get('SPL_TEST_NULL', 'default'));
        $this->assertSame('quoted value', Env::get('SPL_TEST_QUOTED'));
    }

    public function testGetReturnsDefaultForMissingVariable(): void {
        $this->assertNull(Env::get('SPL_TEST_MISSING'));
        $this->assertSame('fallback', Env::get('SPL_TEST_MISSING', 'fallback'));
    }

    public function testLoadDoesNotOverwriteExistingValues(): void {
        $_ENV['SPL_TEST_EXISTING'] = 'original';

        Env::load($this->file);

        $this->assertSame('original', Env::get('SPL_TEST_EXISTING'));
    }

    public function testEnvHelperReadsLoadedValues(): void {
        Env::load($this->file);

        $this->assertSame('hello', env('SPL_TEST_STRING'));
        $this->assertTrue(env('SPL_TEST_TRUE'));
    }

    public function testLoadThrowsForUnreadableFile(): void {
        $this->expectException(RuntimeException::class);
        Env::load($this->file . '.missing');
    }

    public function testSafeLoadIgnoresUnreadableFile(): void {
        Env::safeLoad($this->file . '.missing');

        $this->assertNull(Env::get('SPL_TEST_STRING'));
    }

}
