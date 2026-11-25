<?php declare(strict_types=1);

namespace spl\tests\database;

use PHPUnit\Framework\TestCase;
use spl\database\Connection;
use stdClass;

final class ConnectionTest extends TestCase {

    private Connection $db;

    protected function setUp(): void {
        // SPL_ROOT must stay undefined in tests, otherwise this becomes a file path
        $this->db = new Connection('sqlite://localhost/:memory:');
        $this->db->rawExec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)');
    }

    private function seed(): void {
        $this->db->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Alice', 30]);
        $this->db->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Bob', 25]);
    }

    public function testExecuteReturnsAffectedRowsAndInsertId(): void {
        $this->assertSame(1, $this->db->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Alice', 30]));
        $this->assertSame('1', $this->db->insertId());

        $this->db->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Bob', 25]);

        $this->assertSame(2, $this->db->execute('UPDATE users SET age = age + 1'));
    }

    public function testGetRow(): void {
        $this->seed();

        $this->assertSame(
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            $this->db->getRow('SELECT * FROM users WHERE name = ?', ['Bob']),
        );
        $this->assertSame([], $this->db->getRow('SELECT * FROM users WHERE name = ?', ['Nobody']));
    }

    public function testGetRowWithNamedParameters(): void {
        $this->seed();

        $row = $this->db->getRow('SELECT name FROM users WHERE age > :age', ['age' => 26]);

        $this->assertSame(['name' => 'Alice'], $row);
    }

    public function testGetOne(): void {
        $this->seed();

        $this->assertSame(2, $this->db->getOne('SELECT COUNT(*) FROM users'));
        $this->assertSame('Alice', $this->db->getOne('SELECT name FROM users WHERE id = ?', [1]));
        $this->assertNull($this->db->getOne('SELECT name FROM users WHERE id = ?', [99]));
    }

    public function testGetCol(): void {
        $this->seed();

        $this->assertSame(['Alice', 'Bob'], $this->db->getCol('SELECT name FROM users ORDER BY id'));
    }

    public function testGetAll(): void {
        $this->seed();

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            $this->db->getAll('SELECT id, name FROM users ORDER BY id'),
        );
    }

    public function testCommitPersistsChanges(): void {
        $this->assertTrue($this->db->begin());
        $this->assertTrue($this->db->inTransaction());

        $this->seed();

        $this->assertTrue($this->db->commit());
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(2, $this->db->getOne('SELECT COUNT(*) FROM users'));
    }

    public function testRollbackDiscardsChanges(): void {
        $this->db->begin();
        $this->seed();

        $this->assertTrue($this->db->rollback());
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->db->getOne('SELECT COUNT(*) FROM users'));
    }

    public function testReconnectOpensAFreshConnection(): void {
        $this->assertSame(1, $this->db->getOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'users'"));

        $this->db->reconnect();

        // a new in-memory database has no tables
        $this->assertSame(0, $this->db->getOne('SELECT COUNT(*) FROM sqlite_master'));
    }

    /**
     * Parses a URL with makeDSN() without opening a connection, so non-SQLite URLs can be checked.
     */
    private static function makeDSN(string $url): stdClass {
        $parser = new class extends Connection {
            public function __construct() {}

            public function parse(string $url): stdClass {
                return $this->makeDSN($url);
            }
        };

        return $parser->parse($url);
    }

    public function testMakeDSNDecodesEncodedCredentials(): void {
        $dsn = self::makeDSN('mysql://u%40x:p%2Fs%23s@db.example.com/app');

        $this->assertSame('mysql', $dsn->type);
        $this->assertSame('u@x', $dsn->user);
        $this->assertSame('p/s#s', $dsn->pass);
        $this->assertSame('db.example.com', $dsn->host);
        $this->assertSame(3306, $dsn->port);
        $this->assertSame('app', $dsn->db);
        $this->assertSame('mysql:host=db.example.com;port=3306;dbname=app;charset=utf8mb4', $dsn->pdo);
    }

}
