<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock;

/**
 * migrations:up with a lock the test chooses, through the same seam the driver uses to pick one per platform.
 */
final class CommandWithLock extends MigrationsUpCommand
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(EntityManagerProvider $provider, private readonly MigrationLock $lock, array $settings = [])
    {
        parent::__construct($provider, $settings);
    }

    protected function migrationLock(Connection $connection): MigrationLock
    {
        return $this->lock;
    }
}
