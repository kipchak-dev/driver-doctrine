<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use DI\Container;
use Doctrine\DBAL\Connection;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Doctrine\Doctrine;
use PHPUnit\Framework\TestCase;

/**
 * SQLite through the standard DBAL connection configuration: 'path' for a file, 'memory' for an in-memory database.
 */
final class SqliteConnectionTest extends TestCase
{
    private function connection(array $connection): Connection
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.doctrine' => ['dbal' => ['enabled' => true, 'connections' => ['local' => $connection]]]]);
        Doctrine::initialise($container);

        return Doctrine::get('dbal.local');
    }

    public function testAFileDatabaseIsWrittenAtItsPath(): void
    {
        $file = sys_get_temp_dir() . '/doctrine-sqlite-' . bin2hex(random_bytes(4)) . '.db';
        $db = $this->connection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $db->executeStatement('CREATE TABLE things (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('things', ['name' => 'saddle']);
        $db->close();

        // Read back with plain PDO: the rows are in the file at that path, not in a database that vanished.
        $this->assertFileExists($file);
        $this->assertSame('saddle', (new \PDO('sqlite:' . $file))->query('SELECT name FROM things')->fetchColumn());
        unlink($file);
    }

    public function testAnInMemoryDatabase(): void
    {
        $db = $this->connection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE things (id INTEGER PRIMARY KEY)');
        $db->insert('things', ['id' => 7]);

        $this->assertSame(7, (int) $db->fetchOne('SELECT id FROM things'));
    }

    public function testServerConnectionsKeepTheirParameters(): void
    {
        $params = Doctrine::dbalParams([
            'dbname' => 'mydb', 'user' => 'api', 'password' => 'secret', 'host' => 'mysql', 'port' => 3306,
            'driver' => 'pdo_mysql', 'charset' => 'utf8', 'driverOptions' => [1014 => true],
        ]);

        $this->assertSame([
            'driver' => 'pdo_mysql', 'dbname' => 'mydb', 'user' => 'api', 'password' => 'secret', 'host' => 'mysql',
            'port' => 3306, 'charset' => 'utf8', 'driverOptions' => [1014 => true],
        ], $params);
    }
}
