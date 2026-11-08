<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsDownCommand;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * migrations:up and migrations:down on SQLite, in a project whose SQL files are in migrations/database: each file
 * holds its up statements, then a "-- Down" line and the statements that undo them.
 */
final class MigrationsTest extends TestCase
{
    private string $project;
    private string $cwd;
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/doctrine-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/migrations/database', 0777, true);
        file_put_contents($this->project . '/composer.json', '{}');
        file_put_contents($this->project . '/migrations/database/2026_01_01_000000_shelves.sql',
            "CREATE TABLE shelves (id INTEGER PRIMARY KEY, name TEXT NOT NULL);\n-- Down\nDROP TABLE shelves;\n");
        file_put_contents($this->project . '/migrations/database/2026_01_02_000000_books.sql',
            "CREATE TABLE books (id INTEGER PRIMARY KEY, shelf_id INTEGER NOT NULL, title TEXT NOT NULL);\n"
            . "CREATE INDEX books_shelf ON books (shelf_id);\n-- Down\nDROP TABLE books;\n");
        chdir($this->project);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->project . '/database.db']);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixtures'], true));
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->em->getConnection()->close();
        foreach ([...glob($this->project . '/migrations/database/*'), $this->project . '/database.db', $this->project . '/composer.json'] as $file) {
            @unlink($file);
        }
        @rmdir($this->project . '/migrations/database');
        @rmdir($this->project . '/migrations');
        @rmdir($this->project);
    }

    /**
     * @return array{0: int, 1: string} exit code and output
     */
    private function command(string $class): array
    {
        $tester = new CommandTester(new $class(new SingleManagerProvider($this->em)));
        $code = $tester->execute([]);

        return [$code, $tester->getDisplay()];
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = $this->em->getConnection()->createSchemaManager()->listTableNames();
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function applied(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT filename FROM schema_migrations ORDER BY filename');
    }

    public function testUpAppliesEachFileOnceInFilenameOrder(): void
    {
        [$code, $output] = $this->command(MigrationsUpCommand::class);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['books', 'schema_migrations', 'shelves'], $this->tables());
        self::assertSame(['2026_01_01_000000_shelves.sql', '2026_01_02_000000_books.sql'], $this->applied());
        self::assertLessThan(strpos($output, 'Applied 2026_01_02'), strpos($output, 'Applied 2026_01_01'));

        [$code, $output] = $this->command(MigrationsUpCommand::class);
        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Skipping 2026_01_01_000000_shelves.sql (already applied)', $output);
    }

    public function testDownUndoesTheLastAppliedMigrationOnly(): void
    {
        // Both files are applied within the same second, as they are in a deployment: the later file is the last.
        $this->command(MigrationsUpCommand::class);

        [$code, $output] = $this->command(MigrationsDownCommand::class);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['schema_migrations', 'shelves'], $this->tables());
        self::assertSame(['2026_01_01_000000_shelves.sql'], $this->applied());

        [$code] = $this->command(MigrationsDownCommand::class);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(['schema_migrations'], $this->tables());
        self::assertSame([], $this->applied());
    }

    public function testControlAFailingStatementAppliesNothingFromItsFile(): void
    {
        file_put_contents($this->project . '/migrations/database/2026_01_03_000000_broken.sql',
            "CREATE TABLE loans (id INTEGER PRIMARY KEY);\nINSERT INTO no_such_table VALUES (1);\n-- Down\nDROP TABLE loans;\n");

        [$code, $output] = $this->command(MigrationsUpCommand::class);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('Failed 2026_01_03_000000_broken.sql', $output);
        self::assertNotContains('loans', $this->tables(), 'The file runs in one transaction on SQLite, so its first statement is rolled back');
        self::assertNotContains('2026_01_03_000000_broken.sql', $this->applied());
    }

    public function testControlDownWithoutADownSectionFails(): void
    {
        file_put_contents($this->project . '/migrations/database/2026_01_03_000000_oneway.sql', "CREATE TABLE notes (id INTEGER PRIMARY KEY);\n");
        $this->command(MigrationsUpCommand::class);

        [$code, $output] = $this->command(MigrationsDownCommand::class);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('No down migration found in 2026_01_03_000000_oneway.sql', $output);
        self::assertContains('notes', $this->tables());
    }
}
