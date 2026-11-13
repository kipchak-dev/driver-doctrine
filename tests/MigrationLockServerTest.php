<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLocks;
use Kipchak\Driver\Doctrine\Tests\Support\ServerDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The migration lock against real MySQL 8.4 and PostgreSQL servers, each test in a fresh database. The servers come
 * from the environment (see Support\ServerDatabase and the README): unrequested servers are skipped, requested ones
 * that cannot be reached fail.
 */
final class MigrationLockServerTest extends TestCase
{
    private const RACE_FILE = '2026_01_01_000000_race.sql';

    private ?ServerDatabase $database = null;
    private string $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/doctrine-migration-lock-' . bin2hex(random_bytes(4));
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

    private function database(string $server): ServerDatabase
    {
        return $this->database = ServerDatabase::create($server);
    }

    private function migration(string $filename, string $up, string $down = ''): void
    {
        file_put_contents($this->project . '/migrations/database/' . $filename, $up . "\n-- Down\n" . $down . "\n");
    }

    /**
     * A migration that records each run of it in race_runs, then pauses, so that two runs started together overlap.
     */
    private function plantRace(ServerDatabase $database): void
    {
        $connection = $database->connect();
        $connection->executeStatement('CREATE TABLE race_runs (note VARCHAR(20) NOT NULL)');
        $connection->close();
        $this->migration(
            self::RACE_FILE,
            "INSERT INTO race_runs (note) VALUES ('ran');\n" . $database->sleepStatement(2) . ";\nCREATE TABLE race_widgets (id INT PRIMARY KEY);",
            'DROP TABLE race_widgets;'
        );
    }

    /**
     * Starts two migrations:up processes at the same moment and waits for both.
     *
     * @return list<array{code: int, output: string}>
     */
    private function race(ServerDatabase $database, bool $lock): array
    {
        $command = [PHP_BINARY, __DIR__ . '/bin/migrate.php'];
        if (!$lock) {
            $command[] = '--no-lock';
        }
        $env = [...getenv(), 'KIPCHAK_TEST_DBAL_PARAMS' => json_encode($database->params, JSON_THROW_ON_ERROR)];

        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->project, $env);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes[1]];
        }

        $results = [];
        foreach ($processes as [$process, $stdout]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $results[] = ['code' => proc_close($process), 'output' => $output];
        }

        return $results;
    }

    /**
     * @return array{runs: int, applied: list<string>}
     */
    private function outcome(ServerDatabase $database): array
    {
        $connection = $database->connect();
        $outcome = [
            'runs' => (int) $connection->fetchOne('SELECT COUNT(*) FROM race_runs'),
            'applied' => $connection->fetchFirstColumn('SELECT filename FROM schema_migrations ORDER BY filename'),
        ];
        $connection->close();

        return $outcome;
    }

    private function command(Connection $connection, array $settings): CommandTester
    {
        $em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixtures'], true));

        return new CommandTester(new MigrationsUpCommand(new SingleManagerProvider($em), $settings));
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        $tables = $connection->createSchemaManager()->listTableNames();
        sort($tables);

        return $tables;
    }

    #[DataProvider('servers')]
    public function testTwoRunsAtOnceApplyEachMigrationOnceAndTheSecondWaits(string $server): void
    {
        $database = $this->database($server);
        $this->plantRace($database);

        $results = $this->race($database, true);
        $report = json_encode($results, JSON_PRETTY_PRINT);

        self::assertSame([0, 0], array_column($results, 'code'), $report);
        self::assertSame(['runs' => 1, 'applied' => [self::RACE_FILE]], $this->outcome($database), $report);
        $waited = array_values(array_filter($results, static fn (array $r): bool => str_contains($r['output'], 'Waiting up to 30 s for the migration lock')));
        self::assertCount(1, $waited, $report);
        self::assertStringContainsString('Skipping ' . self::RACE_FILE . ' (already applied)', $waited[0]['output'], $report);
        self::assertMatchesRegularExpression('/Obtained the migration lock kipchak\.migrations\.\S+( \(-?\d+\))? after [0-9.]+ s\./', $waited[0]['output'], $report);
        foreach ($results as $result) {
            self::assertStringNotContainsString('Warning', $result['output'], $report);
            self::assertStringContainsString('Released the migration lock', $result['output'], $report);
        }
    }

    /**
     * The negative control: the same race with the lock switched off must collide, or the test above proves nothing.
     */
    #[DataProvider('servers')]
    public function testControlTwoRunsAtOnceWithoutTheLockCollide(string $server): void
    {
        $database = $this->database($server);
        $this->plantRace($database);

        $results = $this->race($database, false);
        $outcome = $this->outcome($database);
        $report = json_encode(['results' => $results, 'outcome' => $outcome], JSON_PRETTY_PRINT);

        // A collision is the migration executed twice, a skipped "already exists" statement, or a run that failed
        // because the other created the same table or row first: on MySQL the second insert into schema_migrations,
        // on PostgreSQL usually the CREATE TABLE of schema_migrations itself. A run that failed for any other reason
        // (a crash, a connection error) is not a collision.
        $collided = $outcome['runs'] > 1
            || in_array(true, array_map(
                static fn (array $r): bool => str_contains($r['output'], 'Warning')
                    || ($r['code'] !== 0 && preg_match('/already exists|duplicate key|Duplicate entry/i', $r['output']) === 1),
                $results
            ), true);
        self::assertTrue($collided, "The control did not collide, so the race test no longer shows the lock works:\n" . $report);
    }

    #[DataProvider('servers')]
    public function testALockHeldElsewhereTimesOutAndNothingRuns(string $server): void
    {
        $database = $this->database($server);
        $this->migration('2026_01_01_000000_shelves.sql', 'CREATE TABLE shelves (id INT PRIMARY KEY);', 'DROP TABLE shelves;');

        $holder = $database->connect();
        $held = MigrationLocks::for($holder);
        self::assertTrue($held->tryAcquire(), 'The test connection takes the lock first');

        $connection = $database->connect();
        $tester = $this->command($connection, ['lock_timeout' => 1]);
        $started = microtime(true);
        $code = $tester->execute([]);
        $elapsed = microtime(true) - $started;
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertStringContainsString('Waiting up to 1 s for the migration lock ' . $held->name(), $output);
        self::assertStringContainsString('Did not obtain the migration lock ' . $held->name() . ' within 1 s. No migration was run.', $output);
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(10, $elapsed);
        self::assertSame([], $this->tables($connection), 'Nothing is created, not even schema_migrations');

        self::assertTrue($held->release());
        $holder->close();
        $connection->close();
    }

    #[DataProvider('servers')]
    public function testTwoDatabasesOnOneServerDoNotShareALock(string $server): void
    {
        $database = $this->database($server);
        $other = ServerDatabase::create($server);
        try {
            $first = MigrationLocks::for($database->connect());
            $second = MigrationLocks::for($other->connect());

            self::assertNotSame($first->name(), $second->name());
            self::assertTrue($first->tryAcquire());
            self::assertTrue($second->tryAcquire(), 'A lock on one database does not block another on the same server');
            self::assertTrue($first->release());
            self::assertTrue($second->release());
        } finally {
            $other->drop();
        }
    }

    /**
     * strict off, on MySQL, which cannot roll back DDL: a statement that finds its table already there is skipped
     * with a warning naming the file and the statement, and the file is recorded.
     */
    public function testMysqlNotStrictSkipsAnAlreadyExistingTableAndRecordsTheFile(): void
    {
        $database = $this->database('mysql');
        $this->migration('2026_01_01_000000_shelves.sql', 'CREATE TABLE shelves (id INT PRIMARY KEY);', 'DROP TABLE shelves;');
        $this->migration('2026_01_02_000000_again.sql', "CREATE TABLE shelves (id INT PRIMARY KEY);\nCREATE TABLE extra (id INT PRIMARY KEY);", 'DROP TABLE extra;');
        $connection = $database->connect();

        $tester = $this->command($connection, ['strict' => false]);
        $code = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Warning: 2026_01_02_000000_again.sql: skipped a statement that was already applied', $output);
        self::assertStringContainsString('Statement: CREATE TABLE shelves (id INT PRIMARY KEY)', $output);
        self::assertContains('extra', $this->tables($connection));
        self::assertSame(['2026_01_01_000000_shelves.sql', '2026_01_02_000000_again.sql'], $connection->fetchFirstColumn('SELECT filename FROM schema_migrations ORDER BY filename'));
        $connection->close();
    }

    public function testMysqlStrictFailsOnAnAlreadyExistingTableAndDoesNotRecordTheFile(): void
    {
        $database = $this->database('mysql');
        $this->migration('2026_01_01_000000_shelves.sql', 'CREATE TABLE shelves (id INT PRIMARY KEY);', 'DROP TABLE shelves;');
        $this->migration('2026_01_02_000000_again.sql', "CREATE TABLE shelves (id INT PRIMARY KEY);\nCREATE TABLE extra (id INT PRIMARY KEY);", 'DROP TABLE extra;');
        $connection = $database->connect();

        $tester = $this->command($connection, ['strict' => true]);
        $code = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $code, $output);
        self::assertStringContainsString('Failed 2026_01_02_000000_again.sql', $output);
        self::assertNotContains('extra', $this->tables($connection), 'The run stops at the first statement');
        self::assertSame(['2026_01_01_000000_shelves.sql'], $connection->fetchFirstColumn('SELECT filename FROM schema_migrations ORDER BY filename'));
        self::assertStringContainsString('Released the migration lock', $output);
        $connection->close();
    }
}
