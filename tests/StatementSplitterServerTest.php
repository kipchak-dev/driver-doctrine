<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Library\Migrations\StatementSplitter;
use Kipchak\Driver\Doctrine\Tests\Support\ServerDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * migrations:up on MySQL and PostgreSQL with what the split before 1.58 broke: a routine whose body holds ";", a
 * string holding ";", and a comment holding "; DROP TABLE". Needs the servers named in the README.
 */
final class StatementSplitterServerTest extends TestCase
{
    private string $cwd;
    private string $project;
    private ?ServerDatabase $database = null;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/doctrine-splitter-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/migrations/database', 0777, true);
        file_put_contents($this->project . '/composer.json', '{}');
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->database?->drop();
        foreach ([...glob($this->project . '/migrations/database/*'), $this->project . '/composer.json'] as $file) {
            @unlink($file);
        }
        @rmdir($this->project . '/migrations/database');
        @rmdir($this->project . '/migrations');
        @rmdir($this->project);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function servers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('servers')]
    public function testRoutinesStringsAndCommentsRunAsWritten(string $server): void
    {
        $this->database = ServerDatabase::create($server);
        $setup = $this->database->connect();
        $setup->executeStatement('CREATE TABLE keepme (id INT PRIMARY KEY)');
        $setup->close();

        $routine = $server === 'mysql'
            ? "CREATE TRIGGER notes_upper BEFORE INSERT ON notes FOR EACH ROW BEGIN\n  SET NEW.body = UPPER(NEW.body);\n  SET NEW.body = CONCAT(NEW.body, '!');\nEND"
            : "CREATE FUNCTION shout(t TEXT) RETURNS TEXT AS \$fn\$\nBEGIN\n  RETURN UPPER(t) || '!';\nEND;\n\$fn\$ LANGUAGE plpgsql";
        $up = "CREATE TABLE notes (id INT PRIMARY KEY, body VARCHAR(100) NOT NULL);\n"
            . "$routine;\n"
            . "INSERT INTO notes (id, body) VALUES (1, 'semi;colon');\n"
            . "-- tidy up later; DROP TABLE keepme";
        file_put_contents($this->project . '/migrations/database/2026_01_01_000000_notes.sql', $up . "\n-- Down\nDROP TABLE notes;\n");

        // Control: the split before 1.58 cuts the routine, the string and the comment apart.
        self::assertContains('DROP TABLE keepme', StatementSplitter::legacy($up));

        $connection = $this->database->connect();
        $em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixtures'], true));
        $tester = new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($em), ['lock_timeout' => 5]));
        $code = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $code, $tester->getDisplay());
        self::assertContains('keepme', $connection->createSchemaManager()->listTableNames(), 'the DROP in the comment is not run');
        $body = $server === 'mysql'
            ? $connection->fetchOne('SELECT body FROM notes WHERE id = 1')
            : $connection->fetchOne("SELECT shout(body) FROM notes WHERE id = 1");
        self::assertSame('SEMI;COLON!', $body, 'the routine ran whole and the string kept its ";"');
        $connection->close();
    }
}
