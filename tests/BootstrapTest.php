<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase {

    public function testCliIsDetectedInCurrentProcess(): void {
        $this->assertTrue(SPL_CLI);
    }

    public function testCliIsDetectedWhenStdinIsClosed(): void {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        $output = $this->runPhp("fclose(STDIN); require {$autoload}; echo var_export(SPL_CLI, true);");

        $this->assertSame('true', $output);
    }

    /**
     * Run PHP code in a fresh CLI process and return its stdout.
     */
    private function runPhp(string $code): string {
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'Could not start a PHP process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }

}
