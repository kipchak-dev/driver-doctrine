<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Support;

use Doctrine\DBAL\Connection;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock;
use Kipchak\Driver\Doctrine\Library\MigrationLock\NoMigrationLock;

/**
 * The test seam for the control runs: migrations:up with the lock switched off, as releases before 1.57 ran.
 */
final class UnlockedMigrationsUpCommand extends MigrationsUpCommand
{
    protected function migrationLock(Connection $connection): MigrationLock
    {
        return new NoMigrationLock('No migration lock: switched off for a control run.');
    }
}
