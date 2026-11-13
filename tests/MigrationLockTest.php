<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsDownCommand;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLocks;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MysqlMigrationLock;
use Kipchak\Driver\Doctrine\Library\MigrationLock\PostgresMigrationLock;
use Kipchak\Driver\Doctrine\Tests\Support\CommandWithLock;
use Kipchak\Driver\Doctrine\Tests\Support\FakeMigrationLock;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * migrations:up takes the migration lock before it reads schema_migrations and releases it however the run ends, on
 * SQLite with a fake lock. The settings migrations.lock_timeout and migrations.strict, and the SQLite case, where no
 * lock is taken. The lock against real MySQL and PostgreSQL servers is in MigrationLockServerTest.
 */
final class MigrationLockTest extends TestCase
{
    private string $project;
    private string $cwd;
    private EntityManager $em;
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/doctrine-migration-lock-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/migrations/database', 0777, true);
        file_put_contents($this->project . '/composer.json', '{}');
        file_put_contents($this->project . '/migrations/database/2026_01_01_000000_shelves.sql',
            "CREATE TABLE shelves (id INTEGER PRIMARY KEY, name TEXT NOT NULL);\n-- Down\nDROP TABLE shelves;\n");
        chdir($this->project);

        $queries = &$this->queries;
        $logger = new class ($queries) extends AbstractLogger {
            public function __construct(private array &$queries)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $configuration = (new Configuration())->setMiddlewares([new LoggingMiddleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->project . '/database.db'], $configuration);
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
     * @param array<string, mixed>|null $settings
     * @return array{0: int, 1: string}
     */
    private function migrate(FakeMigrationLock $lock, ?array $settings = []): array
    {
        $command = $settings === null
            ? new class (new SingleManagerProvider($this->em), $lock) extends MigrationsUpCommand {
                public function __construct(SingleManagerProvider $provider, private readonly FakeMigrationLock $lock)
                {
                    parent::__construct($provider);
                }

                protected function migrationLock(\Doctrine\DBAL\Connection $connection): \Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock
                {
                    return $this->lock;
                }
            }
            : new CommandWithLock(new SingleManagerProvider($this->em), $lock, $settings);
        $tester = new CommandTester($command);
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

    private function plantBrokenMigration(): void
    {
        file_put_contents($this->project . '/migrations/database/2026_01_02_000000_broken.sql',
            "INSERT INTO no_such_table VALUES (1);\n-- Down\n");
    }

    private function plantAlreadyExisting(): void
    {
        file_put_contents($this->project . '/migrations/database/2026_01_02_000000_again.sql',
            "CREATE TABLE shelves (id INTEGER PRIMARY KEY, name TEXT NOT NULL);\nCREATE TABLE extra (id INTEGER PRIMARY KEY);\n-- Down\nDROP TABLE extra;\n");
    }

    public function testTheLockIsTakenThenReleasedAfterASuccessfulRun(): void
    {
        $lock = new FakeMigrationLock();
        [$code, $output] = $this->migrate($lock);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['try', 'release'], $lock->calls);
        self::assertMatchesRegularExpression('/Obtained the migration lock fake-lock after [0-9.]+ s\./', $output);
        self::assertLessThan(strpos($output, 'Applied 2026_01_01'), strpos($output, 'Obtained the migration lock'));
        self::assertLessThan(strpos($output, 'Released the migration lock fake-lock.'), strpos($output, 'Applied 2026_01_01'));
    }

    public function testTheLockIsTakenBeforeSchemaMigrationsIsCreatedOrRead(): void
    {
        $beforeLock = null;
        $lock = new FakeMigrationLock(onAcquired: function () use (&$beforeLock): void {
            $beforeLock = array_values(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'schema_migrations')));
        });
        $this->migrate($lock);

        self::assertSame([], $beforeLock);
        self::assertNotEmpty(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'schema_migrations')));
    }

    public function testTheLockIsReleasedAfterAFailedMigration(): void
    {
        $this->plantBrokenMigration();
        $lock = new FakeMigrationLock();
        [$code, $output] = $this->migrate($lock);

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertSame(['try', 'release'], $lock->calls);
        self::assertStringContainsString('Released the migration lock fake-lock.', $output);
    }

    public function testTheLockIsReleasedWhenTheRunThrows(): void
    {
        // Once the lock is obtained, a schema_migrations without a filename column appears, so reading it throws out of
        // the command.
        $lock = new FakeMigrationLock(onAcquired: fn () => $this->em->getConnection()->executeStatement('CREATE TABLE schema_migrations (other TEXT)'));

        try {
            $this->migrate($lock);
            self::fail('The run was expected to throw');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('no such column: filename', $e->getMessage());
        }
        self::assertSame(['try', 'release'], $lock->calls);
    }

    public function testASecondRunWaitsForTheLockThenRuns(): void
    {
        $lock = new FakeMigrationLock(free: false);
        [$code, $output] = $this->migrate($lock, ['lock_timeout' => 7]);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['try', 'acquire(7)', 'release'], $lock->calls);
        self::assertStringContainsString('Waiting up to 7 s for the migration lock fake-lock, which another run holds.', $output);
        self::assertSame(['schema_migrations', 'shelves'], $this->tables());
    }

    public function testALockNotObtainedInTimeFailsAndRunsNothing(): void
    {
        $lock = new FakeMigrationLock(free: false, obtainable: false);
        [$code, $output] = $this->migrate($lock, ['lock_timeout' => 1]);

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertSame(['try', 'acquire(1)'], $lock->calls, 'A lock never obtained is not released');
        self::assertStringContainsString('Did not obtain the migration lock fake-lock within 1 s. No migration was run.', $output);
        self::assertSame([], $this->tables(), 'Not even schema_migrations is created without the lock');
    }

    public function testAZeroTimeoutDoesNotWait(): void
    {
        $lock = new FakeMigrationLock(free: false, obtainable: false);
        [$code, $output] = $this->migrate($lock, ['lock_timeout' => 0]);

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertSame(['try'], $lock->calls);
    }

    public function testTheSettingsDefaultToFiveMinutesAndNotStrict(): void
    {
        // No settings passed and the Config driver not booted: the defaults apply.
        $this->plantAlreadyExisting();
        $lock = new FakeMigrationLock(free: false);
        [$code, $output] = $this->migrate($lock, null);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['try', 'acquire(300)', 'release'], $lock->calls);
        self::assertStringContainsString('skipped a statement that was already applied', $output);
    }

    public function testStrictGivenAsTheStringFalseIsOff(): void
    {
        $this->plantAlreadyExisting();
        [$code, $output] = $this->migrate(new FakeMigrationLock(), ['strict' => 'false']);

        self::assertSame(Command::SUCCESS, $code, $output);
    }

    public function testAReleaseTheDatabaseDoesNotConfirmIsAWarningAndKeepsSuccess(): void
    {
        [$code, $output] = $this->migrate(new FakeMigrationLock(release: false));

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Warning: the database did not confirm the release of the migration lock fake-lock', $output);
    }

    public function testAReleaseThatThrowsIsAWarningAndKeepsSuccess(): void
    {
        [$code, $output] = $this->migrate(new FakeMigrationLock(release: new \RuntimeException('connection lost')));

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Warning: could not release the migration lock fake-lock: connection lost', $output);
    }

    public function testAFailedReleaseAfterAFailedMigrationKeepsFailure(): void
    {
        $this->plantBrokenMigration();
        [$code, $output] = $this->migrate(new FakeMigrationLock(release: new \RuntimeException('connection lost')));

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertStringContainsString('Failed 2026_01_02_000000_broken.sql', $output);
    }

    public function testNotStrictSkipsAnAlreadyExistingStatementNamingTheFileAndTheStatement(): void
    {
        $this->plantAlreadyExisting();
        [$code, $output] = $this->migrate(new FakeMigrationLock(), ['strict' => false]);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Warning: 2026_01_02_000000_again.sql: skipped a statement that was already applied', $output);
        self::assertStringContainsString('Statement: CREATE TABLE shelves (id INTEGER PRIMARY KEY, name TEXT NOT NULL)', $output);
        self::assertContains('extra', $this->tables());
        self::assertSame(['2026_01_01_000000_shelves.sql', '2026_01_02_000000_again.sql'], $this->applied());
    }

    public function testTheWarningTruncatesALongStatement(): void
    {
        $columns = implode(', ', array_map(static fn (int $i): string => "column_{$i} INTEGER", range(1, 60)));
        file_put_contents($this->project . '/migrations/database/2026_01_02_000000_wide.sql',
            "CREATE TABLE shelves (\n    {$columns}\n);\n-- Down\n");
        [$code, $output] = $this->migrate(new FakeMigrationLock(), ['strict' => false]);

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertMatchesRegularExpression('/Statement: CREATE TABLE shelves \( column_1 INTEGER, [^\n]{100,200}\.\.\. \(\d+ characters\)/', $output);
    }

    public function testStrictFailsOnAnAlreadyExistingStatementAndDoesNotRecordTheFile(): void
    {
        $this->plantAlreadyExisting();
        [$code, $output] = $this->migrate(new FakeMigrationLock(), ['strict' => true]);

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertStringContainsString('Failed 2026_01_02_000000_again.sql', $output);
        self::assertNotContains('extra', $this->tables());
        self::assertSame(['2026_01_01_000000_shelves.sql'], $this->applied());
    }

    public function testDownTakesTheLockAndReleasesIt(): void
    {
        (new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($this->em), [])))->execute([]);
        $lock = new FakeMigrationLock(free: false);
        $command = new class (new SingleManagerProvider($this->em), $lock) extends MigrationsDownCommand {
            public function __construct(SingleManagerProvider $provider, private readonly FakeMigrationLock $lock)
            {
                parent::__construct($provider, ['lock_timeout' => 4]);
            }

            protected function migrationLock(\Doctrine\DBAL\Connection $connection): \Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock
            {
                return $this->lock;
            }
        };
        $tester = new CommandTester($command);
        $code = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertSame(['try', 'acquire(4)', 'release'], $lock->calls);
        self::assertStringContainsString('Reverted 2026_01_01_000000_shelves.sql', $output);
        self::assertStringContainsString('Released the migration lock fake-lock.', $output);
    }

    public function testDownWithoutTheLockChangesNothing(): void
    {
        (new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($this->em), [])))->execute([]);
        $lock = new FakeMigrationLock(free: false, obtainable: false);
        $command = new class (new SingleManagerProvider($this->em), $lock) extends MigrationsDownCommand {
            public function __construct(SingleManagerProvider $provider, private readonly FakeMigrationLock $lock)
            {
                parent::__construct($provider, ['lock_timeout' => 1]);
            }

            protected function migrationLock(\Doctrine\DBAL\Connection $connection): \Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock
            {
                return $this->lock;
            }
        };
        $tester = new CommandTester($command);
        $code = $tester->execute([]);

        self::assertSame(Command::FAILURE, $code);
        self::assertSame(['try', 'acquire(1)'], $lock->calls);
        self::assertSame(['schema_migrations', 'shelves'], $this->tables());
        self::assertSame(['2026_01_01_000000_shelves.sql'], $this->applied());
    }

    public function testDownAttemptsNoLockOnSqlite(): void
    {
        (new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($this->em), [])))->execute([]);
        $this->queries = [];
        $tester = new CommandTester(new MigrationsDownCommand(new SingleManagerProvider($this->em), []));
        $code = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('No migration lock on SQLite', $output);
        self::assertStringContainsString('Reverted 2026_01_01_000000_shelves.sql', $output);
        foreach ($this->queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/GET_LOCK|RELEASE_LOCK|advisory|applock/i', $sql);
        }
        self::assertNotEmpty($this->queries);
    }

    public function testNoLockIsAttemptedOnSqlite(): void
    {
        $tester = new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($this->em), []));
        $code = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertFalse(MigrationLocks::for($this->em->getConnection())->locks());
        self::assertStringContainsString('No migration lock on SQLite', $output);
        self::assertStringNotContainsString('Released the migration lock', $output);
        foreach ($this->queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/GET_LOCK|RELEASE_LOCK|advisory|applock/i', $sql);
        }
        self::assertNotEmpty($this->queries, 'The query log saw the run');
    }

    public function testTheLockNameIsPerDatabaseAndFitsMysql(): void
    {
        self::assertSame('kipchak.migrations.shop', MysqlMigrationLock::nameFor('shop'));
        self::assertNotSame(MysqlMigrationLock::nameFor('shop'), MysqlMigrationLock::nameFor('billing'));
        $long = MysqlMigrationLock::nameFor(str_repeat('a', 64));
        self::assertLessThanOrEqual(64, strlen($long));
        self::assertNotSame($long, MysqlMigrationLock::nameFor(str_repeat('a', 63) . 'b'));

        self::assertSame(PostgresMigrationLock::keyFor('shop'), PostgresMigrationLock::keyFor('shop'));
        self::assertNotSame(PostgresMigrationLock::keyFor('shop'), PostgresMigrationLock::keyFor('billing'));
    }
}
