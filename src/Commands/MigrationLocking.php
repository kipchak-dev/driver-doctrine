<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLocks;
use Kipchak\Driver\Doctrine\Library\Migrations\StatementSplitter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What migrations:up and migrations:down share: the migration lock, the 'migrations' settings, the
 * schema_migrations table and splitting a section into statements.
 */
trait MigrationLocking
{
    /**
     * A migration section's statements, from StatementSplitter. When the splitter cannot read the section cleanly (an
     * unclosed quote, comment, dollar quote or BEGIN ... END), the section is split as before 1.58, at every ";", and a
     * warning names the file, so an unusual file runs exactly as it did.
     *
     * @return list<string>
     */
    private function sectionStatements(string $sql, Connection $conn, string $filename, OutputInterface $output): array
    {
        $split = StatementSplitter::split($sql, StatementSplitter::dialectFor($conn->getDatabasePlatform()));
        if ($split['clean']) {
            return $split['statements'];
        }
        $output->writeln("<comment>Warning: {$filename}: could not find where every statement ends (a quote, comment or "
            . 'BEGIN ... END block is not closed), so it is split at every ";", as before 1.58.</comment>');

        return StatementSplitter::legacy($sql);
    }

    /**
     * The lock for this connection's platform. Protected so that a test can switch it off for a control run.
     */
    protected function migrationLock(Connection $connection): MigrationLock
    {
        return MigrationLocks::for($connection);
    }

    /**
     * The 'migrations' settings passed to the constructor or, when none were, those from config/kipchak.doctrine.php.
     * None at all when the Config driver is not available or not booted, in which case the defaults apply.
     *
     * @param array<string, mixed>|null $settings
     * @return array<string, mixed>
     */
    private static function migrationSettings(?array $settings): array
    {
        if ($settings !== null) {
            return $settings;
        }
        if (!class_exists(Config::class)) {
            return [];
        }
        try {
            $migrations = Config::get('kipchak.doctrine')['migrations'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        return is_array($migrations) ? $migrations : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function lockTimeout(array $settings): int
    {
        return max(0, (int) ($settings['lock_timeout'] ?? MigrationsUpCommand::DEFAULT_LOCK_TIMEOUT));
    }

    /**
     * PostgreSQL has no DATETIME type, so the table is created with TIMESTAMP there.
     */
    private static function schemaMigrationsTable(Connection $conn): string
    {
        $type = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TIMESTAMP' : 'DATETIME';

        return "CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at {$type} NOT NULL)";
    }

    private function takeLock(MigrationLock $lock, int $timeout, OutputInterface $output): bool
    {
        if (!$lock->locks()) {
            $output->writeln($lock->description());
            return true;
        }

        $name = $lock->name();
        $output->writeln("Taking the migration lock {$name}.");
        $started = microtime(true);
        $obtained = $lock->tryAcquire();
        if (!$obtained && $timeout > 0) {
            $output->writeln("Waiting up to {$timeout} s for the migration lock {$name}, which another run holds.");
            $obtained = $lock->acquire($timeout);
        }
        if (!$obtained) {
            $output->writeln("<error>Did not obtain the migration lock {$name} within {$timeout} s. No migration was run.</error>");
            return false;
        }
        $output->writeln(sprintf('Obtained the migration lock %s after %.1f s.', $name, microtime(true) - $started));

        return true;
    }

    /**
     * A failed release is a warning and leaves the exit code alone: the database releases the lock when the
     * connection closes, which happens when this command's process ends.
     */
    private function releaseLock(MigrationLock $lock, OutputInterface $output): void
    {
        if (!$lock->locks()) {
            return;
        }

        $name = $lock->name();
        try {
            if ($lock->release()) {
                $output->writeln("Released the migration lock {$name}.");
            } else {
                $output->writeln("<comment>Warning: the database did not confirm the release of the migration lock {$name}. It releases the lock when this connection closes.</comment>");
            }
        } catch (\Throwable $e) {
            $output->writeln("<comment>Warning: could not release the migration lock {$name}: {$e->getMessage()} The database releases the lock when this connection closes.</comment>");
        }
    }
}
