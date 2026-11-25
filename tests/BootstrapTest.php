<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase {

    public function testCliIsDetectedWhenStdinIsClosed(): void {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        $output = $this->runPhp("fclose(STDIN); require {$autoload}; echo var_export(SPL_CLI, true);");

        $this->assertSame('true', $output);
    }

    public function testCliIsNotDetectedUnderBuiltInServer(): void {
        $docroot = sys_get_temp_dir() . '/spl-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($docroot);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            "{$docroot}/index.php",
            "<?php require {$autoload}; echo var_export(SPL_CLI, true), ' ', PHP_SAPI;",
        );

        try {
            $this->assertSame('false cli-server', $this->requestFromServer($docroot));
        }
        finally {
            unlink("{$docroot}/index.php");
            rmdir($docroot);
        }
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

    /**
     * Serve $docroot with `php -S` on a free local port and return the body of `GET /`.
     *
     * Marks the test skipped if the server can't be started.
     */
    private function requestFromServer(string $docroot): string {
        // ask the OS for a free port, then release it for the server
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            $this->markTestSkipped('Cannot bind a local TCP port');
        }
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':') ?: ':0', 1);
        fclose($probe);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('Cannot start the PHP built-in server');
        }

        try {
            // wait up to 5 seconds for the server to accept connections
            $deadline = microtime(true) + 5;
            while (($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) === false) {
                if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                    $this->markTestSkipped("The PHP built-in server did not start on port {$port}");
                }
                usleep(50_000);
            }
            fclose($socket);

            $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $body = @file_get_contents("http://127.0.0.1:{$port}/", false, $context);
            $this->assertIsString($body, 'No response from the PHP built-in server');

            return $body;
        }
        finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

}
