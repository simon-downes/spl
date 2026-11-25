<?php declare(strict_types=1);

namespace spl\tests;

use ErrorException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Renders src/error.php, which needs SPL_DEBUG, so each test runs in a separate process.
 */
final class ErrorPageTest extends TestCase {

    #[RunInSeparateProcess]
    public function testDebugPageEscapesExceptionData(): void {
        define('SPL_DEBUG', true);

        $html = $this->render(new ErrorException('<script>alert("x")</script>', 0, E_WARNING, '/tmp/<b>.php', 3));

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('/tmp/&lt;b&gt;.php', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    #[RunInSeparateProcess]
    public function testDebugPageEscapesTraceFilesAndFunctions(): void {
        define('SPL_DEBUG', true);

        // an eval()'d frame has a quote in its file name and every method frame has a '->'
        $error = null;
        try {
            eval('throw new \RuntimeException("in eval");');
        }
        catch (Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error);

        $html = $this->render($error);

        $this->assertStringContainsString(' : eval()&#039;d code', $html);
        $this->assertStringContainsString('ErrorPageTest-&gt;testDebugPageEscapesTraceFilesAndFunctions()', $html);
        $this->assertStringNotContainsString("eval()'d", $html);
        $this->assertStringNotContainsString('ErrorPageTest->', $html);
    }

    #[RunInSeparateProcess]
    public function testProductionPageHidesExceptionData(): void {
        define('SPL_DEBUG', false);

        $html = $this->render(new ErrorException('secret <details>', 0, E_WARNING, '/tmp/secret.php', 3));

        $this->assertStringContainsString('Internal Service Error', $html);
        $this->assertStringNotContainsString('secret', $html);
    }

    private function render(Throwable $error): string {
        ob_start();
        require dirname(__DIR__) . '/src/error.php';
        return (string) ob_get_clean();
    }

}
