<?php declare(strict_types=1);

namespace spl\tests\util;

use ErrorException;
use PHPUnit\Framework\TestCase;
use spl\util\Debug;

final class DebugTest extends TestCase {

    public function testFormatsScalars(): void {
        $debug = new Debug();

        $this->assertSame('null', $debug->toString(null));
        $this->assertSame('bool(true)', $debug->toString(true));
        $this->assertSame('int(42)', $debug->toString(42));
        $this->assertSame('float(1.5)', $debug->toString(1.5));
        $this->assertStringEndsWith('"abc"', $debug->toString('abc'));
    }

    public function testFormatsErrorExceptionWithSeverity(): void {
        $output = (new Debug())->toString(new ErrorException('Something broke', 0, E_WARNING));

        $this->assertStringStartsWith('ErrorException {', $output);
        $this->assertMatchesRegularExpression('/Severity\s*: string\(7[^)]*\) "WARNING"/', $output);
        $this->assertStringContainsString('"Something broke"', $output);
    }

    public function testFormatsNonPublicObjectProperties(): void {
        $object = new class {
            private string $secret = 'hidden';
            protected int $count = 3;
        };

        $output = (new Debug())->toString($object);

        $this->assertMatchesRegularExpression('/secret: string\(6[^)]*\) "hidden"/', $output);
        $this->assertStringContainsString('count: int(3)', $output);
    }

}
