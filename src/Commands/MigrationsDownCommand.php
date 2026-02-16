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
        $root = realpath(__DIR__ . '/../../');
        if ($root === false) {
            $output->writeln('<error>Unable to resolve api root directory.</error>');
            return Command::FAILURE;
        }

        $migrationsDir = $root . '/migrations/database';
        if (!is_dir($migrationsDir)) {
            $output->writeln("<error>Migrations directory not found: {$migrationsDir}</error>");
            return Command::FAILURE;
        }

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
        if ($fileOption === null) {
            $filename = $conn->fetchOne('SELECT filename FROM schema_migrations ORDER BY applied_at DESC LIMIT 1');
            $file = $filename ? $migrationsDir . '/' . $filename : null;
        } else {
            if (is_file($fileOption)) {
                $file = $fileOption;
                $filename = basename($fileOption);
            } elseif (is_file($migrationsDir . '/' . $fileOption)) {
                $file = $migrationsDir . '/' . $fileOption;
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
        if (count($statements) === 0) {
            $output->writeln("<error>No down migration found in {$filename}</error>");
            return Command::FAILURE;
        }

        $conn->beginTransaction();
        try {
            foreach ($statements as $statement) {
                if ($statement !== '') {
                    $conn->executeStatement($statement);
                }
            }
            $conn->executeStatement('DELETE FROM schema_migrations WHERE filename = ?', [$filename]);
            $conn->commit();
            $output->writeln("Reverted {$filename}");
        } catch (\Throwable $e) {
            $conn->rollBack();
            $output->writeln("<error>Failed {$filename}: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
