<?php declare(strict_types=1);

namespace spl\tests\database;

use PHPUnit\Framework\TestCase;
use spl\database\Connection;

final class ConnectionTest extends TestCase {

    private Connection $db;

    protected function setUp(): void {
        // SPL_ROOT must stay undefined in tests, otherwise this becomes a file path
        $this->db = new Connection('sqlite://localhost/:memory:');
        $this->db->rawExec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)');
    }

    public function testReconnectOpensAFreshConnection(): void {
        $this->assertSame(1, $this->db->getOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'users'"));

        $this->db->reconnect();

        // a new in-memory database has no tables
        $this->assertSame(0, $this->db->getOne('SELECT COUNT(*) FROM sqlite_master'));
    }

}
