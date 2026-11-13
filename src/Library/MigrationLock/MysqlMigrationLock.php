<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\MigrationLock;

use Doctrine\DBAL\Connection;

/**
 * MySQL and MariaDB: a named lock, GET_LOCK and RELEASE_LOCK. The name is derived from the database name, so that runs
 * against two databases on one server do not wait for each other.
 */
final class MysqlMigrationLock implements MigrationLock
{
    private const PREFIX = 'kipchak.migrations.';
    // MySQL limits lock names to 64 characters.
    private const MAX_LENGTH = 64;

    private readonly string $name;

    public function __construct(private readonly Connection $connection, string $database)
    {
        $this->name = self::nameFor($database);
    }

    public static function nameFor(string $database): string
    {
        $name = self::PREFIX . $database;

        return strlen($name) <= self::MAX_LENGTH ? $name : self::PREFIX . sha1($database);
    }

    public function locks(): bool
    {
        return true;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return "Migration lock {$this->name} (GET_LOCK).";
    }

    public function tryAcquire(): bool
    {
        return $this->getLock(0);
    }

    public function acquire(int $timeout): bool
    {
        return $this->getLock(max(1, $timeout));
    }

    /**
     * RELEASE_LOCK answers 1 when released, 0 when this session did not hold it and NULL when it did not exist.
     */
    public function release(): bool
    {
        $released = $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name]);

        return $released !== null && (int) $released === 1;
    }

    /**
     * GET_LOCK answers 1 when obtained, 0 on timeout and NULL on an error.
     */
    private function getLock(int $timeout): bool
    {
        $obtained = $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$this->name, $timeout]);

        return $obtained !== null && (int) $obtained === 1;
    }
}
