<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use spl\SPL;
use Twig\Error\LoaderError;

/**
 * SPL::init() defines process-wide constants, so every test runs in its own process.
 */
final class SPLRenderTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/spl-render-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o777, true);
    }

    protected function tearDown(): void {
        $this->removeDir($this->root);
    }

    #[RunInSeparateProcess]
    public function testRenderUsesConfiguredTemplatePath(): void {
        $this->writeTemplate('views/hello.html', 'Hello {{ name }}');
        $this->writeConfig(['twig' => ['template_path' => $this->root . '/views']]);

        SPL::init($this->root, false);

        $this->assertSame('Hello World', SPL::render('hello.html', ['name' => 'World']));
    }

    #[RunInSeparateProcess]
    public function testRenderDefaultsToTemplatesDirectory(): void {
        $this->writeTemplate('templates/hello.html', 'Default {{ name }}');
        $this->writeConfig([]);

        SPL::init($this->root, false);

        $this->assertSame('Default World', SPL::render('hello.html', ['name' => 'World']));
    }

    #[RunInSeparateProcess]
    public function testRenderIgnoresLegacyTemplatesKey(): void {
        $this->writeTemplate('legacy/hello.html', 'Legacy');
        $this->writeConfig(['twig' => ['templates' => $this->root . '/legacy']]);

        SPL::init($this->root, false);

        // the legacy directory exists and holds the template, so only the default path can fail
        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('The "' . $this->root . '/templates" directory does not exist');
        SPL::render('hello.html');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void {
        file_put_contents($this->root . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    }

    private function writeTemplate(string $path, string $content): void {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $content);
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

}
