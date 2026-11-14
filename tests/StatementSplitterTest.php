<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Kipchak\Driver\Doctrine\Library\Migrations\StatementSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where StatementSplitter ends statements, and that it never changes their text. Each case that 1.58 splits
 * differently also shows how the split before 1.58 (legacy) broke it.
 */
final class StatementSplitterTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function split(string $sql, string $dialect = StatementSplitter::DIALECT_MYSQL): array
    {
        $result = StatementSplitter::split($sql, $dialect);
        self::assertTrue($result['clean'], 'the text ends cleanly');

        return $result['statements'];
    }

    public function testTheCardsReproductionNoLongerRunsTheTextOfAComment(): void
    {
        $sql = "CREATE TABLE books (id INT);\n-- note; DROP TABLE keepme";

        // The comment alone is not a statement, so it is not sent at all (PostgreSQL rejects an empty query).
        self::assertSame(['CREATE TABLE books (id INT)'], self::split($sql));
        // Control: before 1.58, the DROP was a statement of its own.
        self::assertContains('DROP TABLE keepme', StatementSplitter::legacy($sql));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function cases(): array
    {
        return [
            'plain statements, as before' => [StatementSplitter::DIALECT_MYSQL,
                "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n",
                ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)']],
            'comments stay with their statement, unchanged' => [StatementSplitter::DIALECT_MYSQL,
                "-- the shelves\nCREATE TABLE a (id INT); # trailing\n/* block */ CREATE TABLE b (id INT);",
                ["-- the shelves\nCREATE TABLE a (id INT)", "# trailing\n/* block */ CREATE TABLE b (id INT)"]],
            'a semicolon in a block comment' => [StatementSplitter::DIALECT_STANDARD,
                "/* one; two */ CREATE TABLE a (id INT); CREATE TABLE b (id INT)",
                ['/* one; two */ CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)']],
            'a semicolon in a string' => [StatementSplitter::DIALECT_STANDARD,
                "INSERT INTO t VALUES ('a;b'); INSERT INTO t VALUES ('it''s; fine')",
                ["INSERT INTO t VALUES ('a;b')", "INSERT INTO t VALUES ('it''s; fine')"]],
            'a backslash-escaped quote in MySQL' => [StatementSplitter::DIALECT_MYSQL,
                "INSERT INTO t VALUES ('it\\'s; fine'); SELECT 1",
                ["INSERT INTO t VALUES ('it\\'s; fine')", 'SELECT 1']],
            'a semicolon in a quoted identifier and backticks' => [StatementSplitter::DIALECT_MYSQL,
                'CREATE TABLE `odd;name` (id INT); CREATE TABLE "x;y" (id INT)',
                ['CREATE TABLE `odd;name` (id INT)', 'CREATE TABLE "x;y" (id INT)']],
            'MySQL: -- without a space is not a comment' => [StatementSplitter::DIALECT_MYSQL,
                'SELECT 1--1; SELECT 2',
                ['SELECT 1--1', 'SELECT 2']],
            'PostgreSQL: # is an operator, not a comment' => [StatementSplitter::DIALECT_POSTGRESQL,
                'SELECT 5 # 3; SELECT 2',
                ['SELECT 5 # 3', 'SELECT 2']],
            'PostgreSQL: dollar quoting' => [StatementSplitter::DIALECT_POSTGRESQL,
                "CREATE FUNCTION f() RETURNS int AS \$body\$ BEGIN RETURN 1; END; \$body\$ LANGUAGE plpgsql; SELECT f()",
                ["CREATE FUNCTION f() RETURNS int AS \$body\$ BEGIN RETURN 1; END; \$body\$ LANGUAGE plpgsql", 'SELECT f()']],
            'MySQL: a trigger body' => [StatementSplitter::DIALECT_MYSQL,
                "CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END;\nSELECT 1",
                ['CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END', 'SELECT 1']],
            'MySQL: a procedure with IF, CASE and nested BEGIN' => [StatementSplitter::DIALECT_MYSQL,
                "-- a routine\nCREATE DEFINER=`app`@`%` PROCEDURE p() BEGIN IF 1 THEN SELECT CASE WHEN 1 THEN 2 ELSE 3 END; END IF; BEGIN SELECT 4; END; END; SELECT 5",
                ["-- a routine\nCREATE DEFINER=`app`@`%` PROCEDURE p() BEGIN IF 1 THEN SELECT CASE WHEN 1 THEN 2 ELSE 3 END; END IF; BEGIN SELECT 4; END; END", 'SELECT 5']],
            'SQLite: a trigger body' => [StatementSplitter::DIALECT_STANDARD,
                "CREATE TRIGGER t AFTER INSERT ON a BEGIN UPDATE b SET n = n + 1; DELETE FROM c; END; SELECT 1",
                ['CREATE TRIGGER t AFTER INSERT ON a BEGIN UPDATE b SET n = n + 1; DELETE FROM c; END', 'SELECT 1']],
            'BEGIN outside a routine is an ordinary statement' => [StatementSplitter::DIALECT_STANDARD,
                'BEGIN; CREATE TABLE a (id INT); COMMIT',
                ['BEGIN', 'CREATE TABLE a (id INT)', 'COMMIT']],
            'a statement of comments alone is dropped' => [StatementSplitter::DIALECT_MYSQL,
                "CREATE TABLE a (id INT);\n-- TODO: backfill later\n# and this\n/* and this */;\nSELECT 1;\n-- the end",
                ['CREATE TABLE a (id INT)', 'SELECT 1']],
            'a column named begin_at or ending is a word, not a block' => [StatementSplitter::DIALECT_MYSQL,
                'CREATE TABLE e (begin_at INT, ending INT); SELECT 1',
                ['CREATE TABLE e (begin_at INT, ending INT)', 'SELECT 1']],
        ];
    }

    #[DataProvider('cases')]
    public function testWhereStatementsEnd(string $dialect, string $sql, array $expected): void
    {
        self::assertSame($expected, self::split($sql, $dialect));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unclosed(): array
    {
        return [
            'a quote' => ["INSERT INTO t VALUES ('a); SELECT 1"],
            'a block comment' => ['/* not closed; SELECT 1'],
            'a routine body' => ['CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1;'],
        ];
    }

    #[DataProvider('unclosed')]
    public function testTextThatDoesNotEndCleanlyIsReportedForTheFallback(string $sql): void
    {
        self::assertFalse(StatementSplitter::split($sql, StatementSplitter::DIALECT_MYSQL)['clean']);
    }

    public function testTheTextOfEveryStatementIsUnchanged(): void
    {
        $sql = "-- header; with a semicolon\nCREATE TABLE a (id INT) /* x; y */;\n\n  INSERT INTO a VALUES (1) -- one\n;";
        $statements = self::split($sql);

        self::assertSame(["-- header; with a semicolon\nCREATE TABLE a (id INT) /* x; y */", "INSERT INTO a VALUES (1) -- one"], $statements);
        foreach ($statements as $statement) {
            self::assertStringContainsString($statement, $sql);
        }
    }

    public function testDialects(): void
    {
        self::assertSame(StatementSplitter::DIALECT_MYSQL, StatementSplitter::dialectFor(new \Doctrine\DBAL\Platforms\MySQL80Platform()));
        self::assertSame(StatementSplitter::DIALECT_POSTGRESQL, StatementSplitter::dialectFor(new \Doctrine\DBAL\Platforms\PostgreSQLPlatform()));
        self::assertSame(StatementSplitter::DIALECT_STANDARD, StatementSplitter::dialectFor(new \Doctrine\DBAL\Platforms\SQLitePlatform()));
    }
}
