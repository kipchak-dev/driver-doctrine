<?php

namespace Kipchak\Driver\Doctrine\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationsUpCommand extends AbstractEntityManagerCommand
{
    protected static $defaultName = 'migrations:up';

    public function __construct(EntityManagerProvider $entityManagerProvider)
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
        if (!$em instanceof EntityManagerInterface) {
            $output->writeln('<error>Unable to resolve Doctrine EntityManager.</error>');
            return Command::FAILURE;
        }

        $conn = $em->getConnection();
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at DATETIME NOT NULL)'
        );

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

            $conn->beginTransaction();
            try {
                foreach ($statements as $statement) {
                    if ($statement !== '') {
                        $conn->executeStatement($statement);
                    }
                }
                $conn->executeStatement(
                    'INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())',
                    [$filename]
                );
                if ($conn->isTransactionActive()) {
                    $conn->commit();
                }
                $output->writeln("Applied {$filename}");
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
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
