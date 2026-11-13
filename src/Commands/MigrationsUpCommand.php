<?php

namespace Kipchak\Driver\Doctrine\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLocks;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationsUpCommand extends AbstractEntityManagerCommand
{
    protected static $defaultName = 'migrations:up';

    /** Seconds to wait for the migration lock when migrations.lock_timeout is not set. */
    public const DEFAULT_LOCK_TIMEOUT = 300;

    /** How much of a skipped statement the warning shows. */
    private const STATEMENT_PREVIEW_LENGTH = 200;

    /**
     * @param array<string, mixed>|null $settings the 'migrations' settings (lock_timeout, strict). Null reads them from
     *                                            the 'migrations' key of config/kipchak.doctrine.php.
     */
    public function __construct(EntityManagerProvider $entityManagerProvider, private readonly ?array $settings = null)
    {
        parent::__construct($entityManagerProvider);
    }

    protected function configure(): void
    {
        $this
            ->setName('migrations:up')
            ->setDescription('Apply SQL migrations from api/migrations/database')
            ->setHelp('Applies SQL files in api/migrations/database once, tracked in schema_migrations.')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Entity manager (handled by bin/doctrine)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Specific migration filename to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->resolveProjectRoot();
        if ($root === null) {
            $output->writeln('<error>Unable to resolve project root directory.</error>');
            return Command::FAILURE;
        }

        $migrationsDir = $root . '/migrations/database';

        $em = $this->getEntityManager($input);

        $conn = $em->getConnection();

        $fileOption = $input->getOption('file');
        if (is_string($fileOption) && trim($fileOption) !== '') {
            $fileOption = trim($fileOption);
            $cwd = getcwd() ?: $root;
            if (is_dir($fileOption)) {
                $files = $this->collectSqlFiles($fileOption);
            } elseif (is_file($fileOption)) {
                $files = [$fileOption];
            } elseif (is_dir($cwd . '/' . $fileOption)) {
                $files = $this->collectSqlFiles($cwd . '/' . trim($fileOption, '/'));
            } elseif (is_file($cwd . '/' . $fileOption)) {
                $files = [$cwd . '/' . ltrim($fileOption, '/')];
            } elseif (is_dir($migrationsDir . '/' . $fileOption)) {
                $files = $this->collectSqlFiles($migrationsDir . '/' . trim($fileOption, '/'));
            } elseif (is_file($migrationsDir . '/' . $fileOption)) {
                $files = [$migrationsDir . '/' . ltrim($fileOption, '/')];
            } else {
                $output->writeln("<error>Migration not found: {$fileOption}</error>");
                return Command::FAILURE;
            }
        } else {
            if (!is_dir($migrationsDir)) {
                $output->writeln("<error>Migrations directory not found: {$migrationsDir}</error>");
                return Command::FAILURE;
            }
            $files = $this->collectSqlFiles($migrationsDir);
        }
        $this->sortByTimestampFilename($files);

        $settings = $this->settings ?? self::configuredSettings();
        $lockTimeout = max(0, (int) ($settings['lock_timeout'] ?? self::DEFAULT_LOCK_TIMEOUT));
        $strict = filter_var($settings['strict'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // The lock is held from before schema_migrations is created or read until the run ends, however it ends. It
        // is taken before the CREATE TABLE IF NOT EXISTS because on PostgreSQL two of those at once on a new database
        // collide in the system catalogue.
        $lock = $this->migrationLock($conn);
        if (!$this->takeLock($lock, $lockTimeout, $output)) {
            return Command::FAILURE;
        }
        try {
            $conn->executeStatement(self::schemaMigrationsTable($conn));

            return $this->applyMigrations($files, $conn, $strict, $output);
        } finally {
            $this->releaseLock($lock, $output);
        }
    }

    /**
     * The lock for this connection's platform. Protected so that a test can switch it off for a control run.
     */
    protected function migrationLock(Connection $connection): MigrationLock
    {
        return MigrationLocks::for($connection);
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

    /**
     * @param list<string> $files
     */
    private function applyMigrations(array $files, Connection $conn, bool $strict, OutputInterface $output): int
    {
        foreach ($files as $file) {
            $filename = basename($file);
            $applied = $conn->fetchOne('SELECT filename FROM schema_migrations WHERE filename = ?', [$filename]);
            if ($applied) {
                $output->writeln("Skipping {$filename} (already applied)");
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                $output->writeln("<error>Unable to read migration: {$filename}</error>");
                return Command::FAILURE;
            }

            $upSql = [];
            foreach (preg_split('/\\R/', $contents) as $line) {
                if (preg_match('/^\\s*--\\s*Down\\b/i', $line)) {
                    break;
                }
                $upSql[] = $line;
            }
            $statements = array_filter(array_map('trim', explode(';', implode("\n", $upSql))));
            $platform = $conn->getDatabasePlatform();
            $supportsDdlTransactions = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
                || (class_exists(\Doctrine\DBAL\Platforms\SQLServerPlatform::class) && $platform instanceof \Doctrine\DBAL\Platforms\SQLServerPlatform);
            $useTransaction = $this->shouldUseTransaction($statements, $supportsDdlTransactions);

            try {
                if ($useTransaction) {
                    $conn->beginTransaction();
                }
                foreach ($statements as $statement) {
                    if ($statement !== '') {
                        if ($useTransaction && $this->isTransactionControlStatement($statement)) {
                            continue;
                        }
                        try {
                            $conn->executeStatement($statement);
                        } catch (\Throwable $e) {
                            // migrations.strict off: an "already exists" error skips the statement, as before 1.57.
                            // On, it fails the migration like any other error.
                            if (!$strict && $this->isGracefulFailure($e)) {
                                $output->writeln("<comment>Warning: {$filename}: skipped a statement that was already applied ("
                                    . trim($e->getMessage()) . '). Statement: ' . $this->statementPreview($statement) . '</comment>');
                                continue;
                            }
                            throw $e;
                        }
                    }
                }
                // The time is bound rather than NOW(), which SQLite does not have.
                $conn->executeStatement(
                    'INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)',
                    [$filename, gmdate('Y-m-d H:i:s')]
                );
                if ($useTransaction && $conn->isTransactionActive()) {
                    $conn->commit();
                }
                $output->writeln("Applied {$filename}");
            } catch (\Throwable $e) {
                if ($useTransaction && $conn->isTransactionActive()) {
                    try {
                        $conn->rollBack();
                    } catch (\Throwable $rollbackError) {
                        // Keep original migration error as primary failure signal.
                    }
                }
                $output->writeln("<error>Failed {$filename}: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * The 'migrations' settings from config/kipchak.doctrine.php, or none when the Config driver is not available or
     * not booted, in which case the defaults apply.
     *
     * @return array<string, mixed>
     */
    private static function configuredSettings(): array
    {
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
     * PostgreSQL has no DATETIME type, so the table is created with TIMESTAMP there.
     */
    private static function schemaMigrationsTable(Connection $conn): string
    {
        $type = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TIMESTAMP' : 'DATETIME';

        return "CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at {$type} NOT NULL)";
    }

    /**
     * The statement on one line, cut to STATEMENT_PREVIEW_LENGTH characters, for the log.
     */
    private function statementPreview(string $statement): string
    {
        $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
        if (mb_strlen($statement) <= self::STATEMENT_PREVIEW_LENGTH) {
            return $statement;
        }

        return mb_substr($statement, 0, self::STATEMENT_PREVIEW_LENGTH) . '... (' . mb_strlen($statement) . ' characters)';
    }

    private function isGracefulFailure(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $gracefulMessages = [
            'Duplicate column name',
            'Table already exists',
            'Duplicate key name',
            'already exists',
        ];

        foreach ($gracefulMessages as $gracefulMessage) {
            if (stripos($message, $gracefulMessage) !== false) {
                return true;
            }
        }

        return false;
    }

    private function collectSqlFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function sortByTimestampFilename(array &$files): void
    {
        usort($files, static function (string $a, string $b): int {
            $nameA = basename($a);
            $nameB = basename($b);
            if ($nameA === $nameB) {
                return strcmp($a, $b);
            }
            return strcmp($nameA, $nameB);
        });
    }

    private function isTransactionControlStatement(string $statement): bool
    {
        $normalized = preg_replace('/(--.*)|(#.*)|(\/\*.*?\*\/)/s', '', $statement);
        if ($normalized === null) {
            $normalized = $statement;
        }
        return preg_match('/^\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $normalized) === 1;
    }

    private function shouldUseTransaction(array $statements, bool $supportsDdlTransactions): bool
    {
        return $supportsDdlTransactions;
    }

    private function resolveProjectRoot(): ?string
    {
        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return null;
        }

        $directory = realpath($cwd);
        if ($directory === false) {
            return null;
        }

        while (true) {
            if (is_file($directory . '/composer.json')) {
                return $directory;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return realpath($cwd) ?: null;
            }
            $directory = $parent;
        }
    }
}
