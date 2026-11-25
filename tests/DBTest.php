<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use spl\DB;
use spl\database\Connection;

final class DBTest extends TestCase {

    public function testDocblockDescribesEveryForwardedMethod(): void {
        $doc = (string) (new ReflectionClass(DB::class))->getDocComment();

        preg_match_all('/@method\s+static\s+.+?\s+(\w+)\(([^)]*)\)/', $doc, $matches, PREG_SET_ORDER);

        $documented = [];
        foreach ($matches as [, $name, $params]) {
            preg_match_all('/\$(\w+)/', $params, $names);
            $documented[$name] = $names[1];
        }

        $expected = [];
        foreach ((new ReflectionClass(Connection::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }
            $expected[$method->getName()] = array_map(
                fn(ReflectionParameter $p) => $p->getName(),
                $method->getParameters(),
            );
        }

        ksort($documented);
        ksort($expected);

        $this->assertSame($expected, $documented);
    }

    #[RunInSeparateProcess]
    public function testForwardsCallsToConnectionFromDbDsn(): void {
        // the facade keeps its connection in a static, so this runs in its own process
        $_ENV['DB_DSN'] = 'sqlite://localhost/:memory:';

        DB::rawExec('CREATE TABLE flags (name TEXT, active INTEGER)');
        DB::execute('INSERT INTO flags (name, active) VALUES (?, ?)', ['on', true]);

        $this->assertSame('1', DB::insertId());
        $this->assertSame([['name' => 'on', 'active' => 1]], DB::getAll('SELECT * FROM flags'));
        $this->assertSame('sqlite', DB::getInfo()['driver']);
    }

}
