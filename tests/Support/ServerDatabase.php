<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Assert;

/**
 * A fresh database on a MySQL or PostgreSQL server, for the tests that need a real server.
 *
 * The server is given in the environment, for example for MySQL:
 *
 *     DOCTRINE_TEST_MYSQL_HOST=127.0.0.1 DOCTRINE_TEST_MYSQL_PORT=33061
 *     DOCTRINE_TEST_MYSQL_USER=root DOCTRINE_TEST_MYSQL_PASSWORD=...
 *
 * and the same with DOCTRINE_TEST_PGSQL_ for PostgreSQL. Setting the HOST variable requests that server's tests: they
 * then fail, never skip, when the server cannot be reached. Without it, they are skipped. The README's testing section
 * has the Docker commands.
 */
final class ServerDatabase
{
    public const SERVERS = ['mysql', 'pgsql'];

    /**
     * @param array<string, mixed> $params
     */
    private function __construct(public readonly string $server, public readonly array $params)
    {
    }

    /**
     * Creates an empty database with a unique name, or skips the test when the server was not requested.
     */
    public static function create(string $server): self
    {
        $prefix = 'DOCTRINE_TEST_' . strtoupper($server) . '_';
        $host = getenv($prefix . 'HOST');
        if ($host === false || $host === '') {
            Assert::markTestSkipped("Set {$prefix}HOST (and PORT, USER, PASSWORD) to run the {$server} tests. See the README.");
        }

        $base = [
            'driver' => $server === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql',
            'host' => $host,
            'port' => (int) (getenv($prefix . 'PORT') ?: ($server === 'mysql' ? 3306 : 5432)),
            'user' => getenv($prefix . 'USER') ?: ($server === 'mysql' ? 'root' : 'postgres'),
            'password' => (string) getenv($prefix . 'PASSWORD'),
        ];
        if ($server === 'pgsql') {
            $base['dbname'] = 'postgres';
        }

        $name = 'kipchak_migrations_' . bin2hex(random_bytes(4));
        $admin = DriverManager::getConnection($base);
        $admin->executeStatement('CREATE DATABASE ' . $name);
        $admin->close();

        return new self($server, [...$base, 'dbname' => $name]);
    }

    public function connect(): Connection
    {
        return DriverManager::getConnection($this->params);
    }

    public function drop(): void
    {
        $admin = DriverManager::getConnection([...$this->params, 'dbname' => $this->server === 'mysql' ? null : 'postgres']);
        if ($this->server === 'pgsql') {
            $admin->executeStatement('DROP DATABASE IF EXISTS ' . $this->params['dbname'] . ' WITH (FORCE)');
        } else {
            $admin->executeStatement('DROP DATABASE IF EXISTS ' . $this->params['dbname']);
        }
        $admin->close();
    }

    /**
     * A statement that makes a migration pause for the given seconds. The runner executes statements without reading
     * their results, so MySQL uses DO rather than SELECT.
     */
    public function sleepStatement(int $seconds): string
    {
        return $this->server === 'mysql' ? "DO SLEEP({$seconds})" : "SELECT pg_sleep({$seconds})";
    }
}
