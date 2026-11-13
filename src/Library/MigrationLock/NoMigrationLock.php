<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\MigrationLock;

/**
 * No lock, on platforms that have none or need none. The description says why, for the log.
 */
final class NoMigrationLock implements MigrationLock
{
    public function __construct(private readonly string $description)
    {
    }

    public function locks(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function description(): string
    {
        return $this->description;
    }

    public function tryAcquire(): bool
    {
        return true;
    }

    public function acquire(int $timeout): bool
    {
        return true;
    }

    public function release(): bool
    {
        return true;
    }
}
