<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\MigrationLock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * PostgreSQL: a session advisory lock, pg_advisory_lock and pg_advisory_unlock, on a bigint key derived from a name
 * that includes the database name.
 *
 * pg_advisory_lock waits without limit, so the wait is bounded with lock_timeout, set for the one statement and put
 * back afterwards.
 */
final class PostgresMigrationLock implements MigrationLock
{
    // SQLSTATE lock_not_available, raised when lock_timeout expires.
    private const LOCK_NOT_AVAILABLE = '55P03';

    private readonly string $name;
    private readonly int $key;

    public function __construct(private readonly Connection $connection, string $database)
    {
        $this->name = 'kipchak.migrations.' . $database;
        $this->key = self::keyFor($this->name);
    }

    /**
     * A stable 64-bit key: the first eight bytes of the name's SHA-1, read big-endian. PHP integers are signed, so the
     * key fits PostgreSQL's bigint.
     */
    public static function keyFor(string $name): int
    {
        return unpack('J', substr(sha1($name, true), 0, 8))[1];
    }

    public function locks(): bool
    {
        return true;
    }

    public function name(): string
    {
        return "{$this->name} ({$this->key})";
    }

    public function description(): string
    {
        return "Migration lock {$this->name()} (pg_advisory_lock).";
    }

    public function tryAcquire(): bool
    {
        return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(CAST(? AS BIGINT))', [(string) $this->key]);
    }

    public function acquire(int $timeout): bool
    {
        $previous = (string) $this->connection->fetchOne("SELECT current_setting('lock_timeout')");
        $this->connection->fetchOne("SELECT set_config('lock_timeout', ?, false)", [max(1, $timeout) * 1000 . 'ms']);
        try {
            $this->connection->fetchOne('SELECT pg_advisory_lock(CAST(? AS BIGINT))', [(string) $this->key]);

            return true;
        } catch (DriverException $e) {
            if ($e->getSQLState() === self::LOCK_NOT_AVAILABLE) {
                return false;
            }
            throw $e;
        } finally {
            $this->connection->fetchOne("SELECT set_config('lock_timeout', ?, false)", [$previous]);
        }
    }

    /**
     * pg_advisory_unlock answers false, with a warning, when this session did not hold the lock.
     */
    public function release(): bool
    {
        return (bool) $this->connection->fetchOne('SELECT pg_advisory_unlock(CAST(? AS BIGINT))', [(string) $this->key]);
    }
}
