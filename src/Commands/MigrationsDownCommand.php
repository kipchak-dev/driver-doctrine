<?php

namespace Kipchak\Driver\Doctrine\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationsDownCommand extends AbstractEntityManagerCommand
{
    protected static $defaultName = 'migrations:down';

    public function __construct(EntityManagerProvider $entityManagerProvider)
    {
        parent::__construct($entityManagerProvider);
    }

    protected function configure(): void
    {
        $this
            ->setName('migrations:down')
            ->setDescription('Revert SQL migrations from api/migrations/database')
            ->setHelp('Reverts the last applied migration or a specific file if provided.')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Entity manager (handled by bin/doctrine)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Specific migration filename to revert');
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
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at DATETIME NOT NULL)'
        );

        $fileOption = $input->getOption('file');
        if ($fileOption === null || (is_string($fileOption) && trim($fileOption) === '')) {
            if (!is_dir($migrationsDir)) {
                $output->writeln("<error>Migrations directory not found: {$migrationsDir}</error>");
                return Command::FAILURE;
            }
            $filename = $conn->fetchOne('SELECT filename FROM schema_migrations ORDER BY applied_at DESC LIMIT 1');
            $file = $filename ? $migrationsDir . '/' . $filename : null;
        } else {
            $fileOption = trim($fileOption);
            $cwd = getcwd() ?: $root;
            if (is_file($fileOption)) {
                $file = $fileOption;
                $filename = basename($fileOption);
            } elseif (is_file($cwd . '/' . $fileOption)) {
                $file = $cwd . '/' . ltrim($fileOption, '/');
                $filename = basename($fileOption);
            } elseif (is_file($migrationsDir . '/' . $fileOption)) {
                $file = $migrationsDir . '/' . ltrim($fileOption, '/');
                $filename = basename($fileOption);
            } else {
                $output->writeln("<error>Migration not found: {$fileOption}</error>");
                return Command::FAILURE;
            }
        }
        if (!$filename) {
            $output->writeln('<error>No applied migrations found.</error>');
            return Command::FAILURE;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $output->writeln("<error>Unable to read migration: {$filename}</error>");
            return Command::FAILURE;
        }

        $downSql = [];
        $inDown = false;
        foreach (preg_split('/\\R/', $contents) as $line) {
            if (preg_match('/^\\s*--\\s*Down\\b/i', $line)) {
                $inDown = true;
                continue;
            }
            if ($inDown) {
                $downSql[] = $line;
            }
        }
        $statements = array_filter(array_map('trim', explode(';', implode("\n", $downSql))));
        $platform = $conn->getDatabasePlatform();
        $supportsDdlTransactions = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
            || (class_exists(\Doctrine\DBAL\Platforms\SQLServerPlatform::class) && $platform instanceof \Doctrine\DBAL\Platforms\SQLServerPlatform);
        $useTransaction = $this->shouldUseTransaction($statements, $supportsDdlTransactions);
        if (count($statements) === 0) {
            $output->writeln("<error>No down migration found in {$filename}</error>");
            return Command::FAILURE;
        }

        try {
            if ($useTransaction) {
                $conn->beginTransaction();
            }
            foreach ($statements as $statement) {
                if ($statement !== '') {
                    if ($this->isTransactionControlStatement($statement)) {
                        continue;
                    }
                    $conn->executeStatement($statement);
                }
            }
            $conn->executeStatement('DELETE FROM schema_migrations WHERE filename = ?', [$filename]);
            if ($useTransaction && $conn->isTransactionActive()) {
                $conn->commit();
            }
            $output->writeln("Reverted {$filename}");
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

        return Command::SUCCESS;
    }

    private function isTransactionControlStatement(string $statement): bool
    {
        return preg_match('/^\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $statement) === 1;
    }

    private function shouldUseTransaction(array $statements, bool $supportsDdlTransactions): bool
    {
        if ($supportsDdlTransactions) {
            return true;
        }

        foreach ($statements as $statement) {
            if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $statement) === 1) {
                return false;
            }
        }

        return true;
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
