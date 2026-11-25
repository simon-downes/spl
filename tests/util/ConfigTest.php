<?php declare(strict_types=1);

namespace spl\tests\util;

use LogicException;
use PHPUnit\Framework\TestCase;
use spl\util\Config;

final class ConfigTest extends TestCase {

    private Config $config;

    protected function setUp(): void {
        $this->config = new Config([
            'name' => 'app',
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'options' => ['timeout' => 5],
            ],
        ]);
    }

    public function testGetReturnsTopLevelValue(): void {
        $this->assertSame('app', $this->config->get('name'));
    }

    public function testGetResolvesDotNotation(): void {
        $this->assertSame('localhost', $this->config->get('database.host'));
        $this->assertSame(3306, $this->config->get('database.port'));
        $this->assertSame(5, $this->config->get('database.options.timeout'));
        $this->assertSame(['timeout' => 5], $this->config->get('database.options'));
    }

    public function testGetReturnsDefaultForMissingKey(): void {
        $this->assertNull($this->config->get('database.user'));
        $this->assertSame('root', $this->config->get('database.user', 'root'));
        $this->assertSame('x', $this->config->get('missing.deeply.nested', 'x'));
    }

    public function testHas(): void {
        $this->assertTrue($this->config->has('database.host'));
        $this->assertFalse($this->config->has('database.user'));
    }

    public function testArrayAccessReads(): void {
        $this->assertSame('localhost', $this->config['database.host']);
        $this->assertTrue(isset($this->config['database.port']));
        $this->assertFalse(isset($this->config['database.user']));
    }

    public function testIsImmutable(): void {
        $this->expectException(LogicException::class);
        $this->config['name'] = 'other';
    }

    public function testLoadReadsPhpFile(): void {
        $file = tempnam(sys_get_temp_dir(), 'spl-config-');
        $this->assertIsString($file);

        try {
            file_put_contents($file, "<?php return ['app' => ['debug' => true]];");
            $this->assertTrue(Config::load($file)->get('app.debug'));
        }
        finally {
            unlink($file);
        }
    }

}
