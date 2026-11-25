<?php declare(strict_types=1);

namespace spl\tests\database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PDO;
use RuntimeException;
use spl\database\Connection;
use stdClass;

final class ConnectionTest extends TestCase {

    private Connection $db;

    /**
     * Temporary files and directories to remove, files before their directories.
     *
     * @var list<string>
     */
    private array $cleanup = [];

    protected function setUp(): void {
        // SPL_ROOT must stay undefined in tests, otherwise this becomes a file path
        $this->db = new Connection('sqlite://localhost/:memory:');
        $this->db->rawExec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER)');
    }

    protected function tearDown(): void {
        // remove newest first so files go before the directories that hold them
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
            elseif (file_exists($path)) {
                unlink($path);
            }
        }
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

    /**
     * Returns a new temporary file path that tearDown() removes.
     */
    private function tempFile(): string {
        $file = tempnam(sys_get_temp_dir(), 'spl-db-');
        $this->assertIsString($file);
        $this->cleanup[] = $file;

        return $file;
    }

    public function testOpensAbsoluteSqlitePath(): void {
        $file = $this->tempFile();

        // $file is absolute, so the URL is sqlite:///tmp/...
        $db = new Connection('sqlite://' . $file);
        $db->rawExec('CREATE TABLE t (v TEXT)');
        $db->execute('INSERT INTO t (v) VALUES (?)', ['stored']);

        $this->assertSame('sqlite:' . $file, $db->getDSN()->pdo);
        $this->assertSame($file, $db->getDSN()->db);
        $this->assertSame('stored', (new Connection('sqlite://' . $file))->getOne('SELECT v FROM t'));
    }

    public function testDecodesSqlitePath(): void {
        $dir = $this->tempFile();
        unlink($dir);
        mkdir($dir);
        $this->cleanup[] = $dir . '/my db.db';

        $db = new Connection('sqlite://' . $dir . '/my%20db.db');
        $db->rawExec('CREATE TABLE t (v TEXT)');

        $this->assertFileExists($dir . '/my db.db');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function memoryURLProvider(): array {
        return [
            'with host'    => ['sqlite://localhost/:memory:'],
            'without host' => ['sqlite:///:memory:'],
        ];
    }

    #[DataProvider('memoryURLProvider')]
    public function testMemoryDatabaseForms(string $url): void {
        $this->assertSame('sqlite::memory:', (new Connection($url))->getDSN()->pdo);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingDatabaseProvider(): array {
        return [
            'absolute' => ['sqlite:///'],
            'relative' => ['sqlite://localhost/'],
        ];
    }

    #[DataProvider('missingDatabaseProvider')]
    public function testRejectsSqliteURLWithoutDatabase(string $url): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No database name specified');

        new Connection($url);
    }

    public function testRejectsFourSlashSqliteURL(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid database configuration');

        self::makeDSN('sqlite:////abs/path.db');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingHostProvider(): array {
        return [
            'mysql empty authority'  => ['mysql:///app'],
            'pgsql empty authority'  => ['pgsql:///app'],
            'sqlsrv empty authority' => ['sqlsrv:///app'],
            'mysql no authority'     => ['mysql:app'],
        ];
    }

    #[DataProvider('missingHostProvider')]
    public function testRejectsNetworkURLWithoutHost(string $url): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No database host specified');

        self::makeDSN($url);
    }

    #[RunInSeparateProcess]
    public function testMemoryDatabaseIsNotPrefixedWithRoot(): void {
        // a directory that doesn't exist, so a prefixed path fails instead of creating a file
        define('SPL_ROOT', sys_get_temp_dir() . '/spl-missing-root-' . bin2hex(random_bytes(4)));

        $this->assertSame('sqlite::memory:', (new Connection('sqlite://localhost/:memory:'))->getDSN()->pdo);
    }

    #[RunInSeparateProcess]
    public function testAbsoluteSqlitePathIsNotPrefixedWithRoot(): void {
        define('SPL_ROOT', sys_get_temp_dir() . '/spl-missing-root-' . bin2hex(random_bytes(4)));
        $file = $this->tempFile();

        $this->assertSame('sqlite:' . $file, (new Connection('sqlite://' . $file))->getDSN()->pdo);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostlessSqlitePathProvider(): array {
        return [
            'relative path' => ['sqlite:db/app.db', '/db/app.db'],
            'relative file' => ['sqlite:app.db', '/app.db'],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('hostlessSqlitePathProvider')]
    public function testHostlessRelativeSqlitePathIsPrefixedWithRoot(string $url, string $suffix): void {
        // a directory that doesn't exist; makeDSN() doesn't open the file
        $root = sys_get_temp_dir() . '/spl-missing-root-' . bin2hex(random_bytes(4));
        define('SPL_ROOT', $root);

        $this->assertSame('sqlite:' . $root . $suffix, self::makeDSN($url)->pdo);
    }

    #[RunInSeparateProcess]
    public function testHostlessAbsoluteSqlitePathIsNotPrefixedWithRoot(): void {
        define('SPL_ROOT', sys_get_temp_dir() . '/spl-missing-root-' . bin2hex(random_bytes(4)));

        $this->assertSame('sqlite:/var/data/app.db', self::makeDSN('sqlite:/var/data/app.db')->pdo);
    }

    #[RunInSeparateProcess]
    public function testRelativeSqlitePathIsPrefixedWithRoot(): void {
        $root = $this->tempFile();
        unlink($root);
        mkdir($root);
        $this->cleanup[] = $root . '/rel.db';
        define('SPL_ROOT', $root);

        $db = new Connection('sqlite://localhost/rel.db');
        $db->rawExec('CREATE TABLE t (v TEXT)');

        $this->assertSame('sqlite:' . $root . '/rel.db', $db->getDSN()->pdo);
        $this->assertSame('rel.db', $db->getDSN()->db);
        $this->assertFileExists($root . '/rel.db');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function bindTypeProvider(): array {
        return [
            'false'  => [false, 'integer'],
            'true'   => [true, 'integer'],
            'null'   => [null, 'null'],
            'int'    => [5, 'integer'],
            'string' => ['5', 'text'],
            'float'  => [1.5, 'text'],
        ];
    }

    #[DataProvider('bindTypeProvider')]
    public function testBindsParameterTypes(mixed $value, string $type): void {
        $this->assertSame($type, $this->db->getOne('SELECT typeof(?)', [$value]));
    }

    public function testBindsBoolValues(): void {
        $this->db->rawExec('CREATE TABLE flags (name TEXT, active INTEGER)');
        $this->db->execute('INSERT INTO flags (name, active) VALUES (?, ?)', ['off', false]);
        $this->db->execute('INSERT INTO flags (name, active) VALUES (?, ?)', ['on', true]);

        $this->assertSame(['off' => 0, 'on' => 1], $this->db->getAssoc('SELECT name, active FROM flags ORDER BY name'));
        $this->assertSame('off', $this->db->getOne('SELECT name FROM flags WHERE active = :flag', ['flag' => false]));
    }

    public function testGetInfoForSqlite(): void {
        $this->seed();

        $info = $this->db->getInfo();
        $version = (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_SERVER_VERSION);

        $this->assertSame('sqlite', $info['driver']);
        $this->assertSame($version, $info['server']);
        $this->assertSame($version, $info['client']);
        $this->assertSame('sqlite::memory:', $info['dsn']->pdo);
        $this->assertSame(3, $info['stats']->query_count);
    }

    public function testGetInfoAppendsServerInfoForOtherDrivers(): void {
        // SQLite doesn't support ATTR_SERVER_INFO, so stub it and pretend to be MySQL
        $db = new class ('sqlite://localhost/:memory:') extends Connection {
            public function reconnect(): void {
                $this->pdo = new class ('sqlite::memory:') extends PDO {
                    public function getAttribute(int $attribute): mixed {
                        return $attribute === PDO::ATTR_SERVER_INFO ? 'Uptime: 42' : parent::getAttribute($attribute);
                    }
                };
            }

            public function setType(string $type): void {
                $this->dsn->type = $type;
            }
        };
        $db->setType(Connection::TYPE_MYSQL);

        $version = (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_SERVER_VERSION);

        $this->assertSame("{$version}: Uptime: 42", $db->getInfo()['server']);
    }

}
