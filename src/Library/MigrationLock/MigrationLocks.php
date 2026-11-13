<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\MigrationLock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Picks the migration lock for a connection's platform.
 */
final class MigrationLocks
{
    public static function for(Connection $connection): MigrationLock
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            // MySQL and MariaDB.
            return new MysqlMigrationLock($connection, (string) $connection->fetchOne('SELECT DATABASE()'));
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return new PostgresMigrationLock($connection, (string) $connection->fetchOne('SELECT current_database()'));
        }

        if ($platform instanceof SQLitePlatform) {
            return new NoMigrationLock(
                'No migration lock on SQLite: SQLite allows one writer at a time and runs each migration in a transaction.'
            );
        }

        return new NoMigrationLock(sprintf(
            'No migration lock on %s: the driver has no lock for this platform. Do not start two runs at once.',
            (new \ReflectionClass($platform))->getShortName()
        ));
    }
}
