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

    public function testLoadAcceptsExportPrefix(): void {
        $this->writeEnv([
            'export SPL_TEST_EXPORTED=yes',
            'export  SPL_TEST_EXPORTED_SPACED = also',
        ]);

        Env::load($this->file);

        $this->assertSame('yes', Env::get('SPL_TEST_EXPORTED'));
        $this->assertSame('also', Env::get('SPL_TEST_EXPORTED_SPACED'));
    }

    public function testLoadStripsInlineCommentsFromUnquotedValues(): void {
        $this->writeEnv([
            'SPL_TEST_COMMENTED=bar # a comment',
            'SPL_TEST_TABBED=bar	# a comment',
            'SPL_TEST_COLOUR=#fff',
            'SPL_TEST_URL=http://host/path#frag',
            'SPL_TEST_ONLY_COMMENT= # nothing yet',
            'SPL_TEST_EMPTY=',
        ]);

        Env::load($this->file);

        $this->assertSame('bar', Env::get('SPL_TEST_COMMENTED'));
        $this->assertSame('bar', Env::get('SPL_TEST_TABBED'));
        $this->assertSame('#fff', Env::get('SPL_TEST_COLOUR'));
        $this->assertSame('http://host/path#frag', Env::get('SPL_TEST_URL'));
        $this->assertSame('', Env::get('SPL_TEST_ONLY_COMMENT'));
        $this->assertSame('', Env::get('SPL_TEST_EMPTY'));
    }

    public function testLoadKeepsHashInsideQuotedValues(): void {
        $this->writeEnv([
            'SPL_TEST_DQ="x # y" # trailing comment',
            "SPL_TEST_SQ='x # y'",
        ]);

        Env::load($this->file);

        $this->assertSame('x # y', Env::get('SPL_TEST_DQ'));
        $this->assertSame('x # y', Env::get('SPL_TEST_SQ'));
    }

    public function testLoadStripsQuotesBeforeStoringAndInterpolating(): void {
        $this->writeEnv([
            'SPL_TEST_Q="quoted value"',
            'SPL_TEST_R=${SPL_TEST_Q}!',
            "SPL_TEST_INNER='\"kept\"'",
            'SPL_TEST_EMPTY_QUOTES=""',
            'SPL_TEST_UNTERMINATED="unterminated',
            'SPL_TEST_TRAILING="a"b',
        ]);

        Env::load($this->file);

        $this->assertSame('quoted value', $_ENV['SPL_TEST_Q']);
        $this->assertSame('quoted value!', Env::get('SPL_TEST_R'));
        $this->assertSame('"kept"', Env::get('SPL_TEST_INNER'));
        $this->assertSame('', Env::get('SPL_TEST_EMPTY_QUOTES'));
        $this->assertSame('"unterminated', Env::get('SPL_TEST_UNTERMINATED'));
        $this->assertSame('"a"b', Env::get('SPL_TEST_TRAILING'));
    }

    public function testLoadInterpolatesOnlyOutsideSingleQuotes(): void {
        $this->writeEnv([
            'SPL_TEST_STRING=hello',
            "SPL_TEST_SINGLE='\${SPL_TEST_STRING}'",
            'SPL_TEST_DOUBLE="${SPL_TEST_STRING} there"',
        ]);

        Env::load($this->file);

        $this->assertSame('${SPL_TEST_STRING}', Env::get('SPL_TEST_SINGLE'));
        $this->assertSame('hello there', Env::get('SPL_TEST_DOUBLE'));
    }

    public function testLoadSkipsLinesWithoutAssignment(): void {
        $this->writeEnv([
            'NOT_AN_ASSIGNMENT',
            'export SPL_TEST_NO_VALUE',
            '=orphan',
            'SPL_TEST_AFTER=loaded',
        ]);

        Env::load($this->file);

        $this->assertSame('loaded', Env::get('SPL_TEST_AFTER'));
        $this->assertArrayNotHasKey('NOT_AN_ASSIGNMENT', $_ENV);
        $this->assertArrayNotHasKey('', $_ENV);
    }

    public function testLoadHandlesWindowsLineEndings(): void {
        file_put_contents($this->file, "SPL_TEST_CRLF=\"value\"\r\nSPL_TEST_CRLF_PLAIN=plain\r\n");

        Env::load($this->file);

        $this->assertSame('value', Env::get('SPL_TEST_CRLF'));
        $this->assertSame('plain', Env::get('SPL_TEST_CRLF_PLAIN'));
    }

    public function testLoadThrowsForUndefinedReference(): void {
        $this->writeEnv(['SPL_TEST_BROKEN="${SPL_TEST_UNDEFINED}"']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Undefined environment variable: 'SPL_TEST_UNDEFINED'");
        Env::load($this->file);
    }

    public function testGetReturnsEnvironmentValuesVerbatim(): void {
        $_ENV['SPL_TEST_RAW'] = '"kept"';

        $this->assertSame('"kept"', Env::get('SPL_TEST_RAW'));
    }

    /**
     * @param list<string> $lines
     */
    private function writeEnv(array $lines): void {
        file_put_contents($this->file, implode("\n", $lines) . "\n");
    }

}
