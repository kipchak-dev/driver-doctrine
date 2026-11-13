<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\MigrationLock;

/**
 * The lock migrations:up holds for its whole run, so that two runs against one database (two pods starting at once,
 * say) cannot both find a migration unapplied and both execute it.
 *
 * It is a session lock on the connection that runs the migrations. The database releases it when that connection
 * closes, so a run that dies does not leave it held.
 */
interface MigrationLock
{
    /**
     * Whether this lock locks anything. False on platforms without one, such as SQLite.
     */
    public function locks(): bool;

    /**
     * The lock's name, for the log.
     */
    public function name(): string;

    /**
     * One line for the log saying what the lock is, or why there is none.
     */
    public function description(): string;

    /**
     * Takes the lock if it is free, without waiting.
     */
    public function tryAcquire(): bool;

    /**
     * Waits up to $timeout seconds (at least 1) for the lock. True once obtained, false when the time ran out.
     */
    public function acquire(int $timeout): bool;

    /**
     * Releases the lock. False when the database did not confirm the release. It may also throw, for example on a lost
     * connection.
     */
    public function release(): bool;
}
