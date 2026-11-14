<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Splits a migration section into the statements sent to the database, one at a time.
 *
 * A ";" ends a statement only outside comments, quoted strings and identifiers, PostgreSQL dollar quotes and the
 * BEGIN ... END body of a CREATE TRIGGER, PROCEDURE, FUNCTION or EVENT. Before 1.58 every ";" ended a statement, so
 * text after a ";" in a comment ran as SQL.
 *
 * The splitter only decides where statements end: it never removes or changes text, so each statement reaches the
 * database exactly as written, comments included. A file without a ";" in a comment, quote or body splits exactly as
 * before 1.58.
 */
final class StatementSplitter
{
    public const DIALECT_MYSQL = 'mysql';
    public const DIALECT_POSTGRESQL = 'postgresql';
    public const DIALECT_STANDARD = 'standard';

    public static function dialectFor(AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => self::DIALECT_MYSQL,
            $platform instanceof PostgreSQLPlatform => self::DIALECT_POSTGRESQL,
            default => self::DIALECT_STANDARD,
        };
    }

    /**
     * The split before 1.58: at every ";", trimmed, empty pieces dropped.
     *
     * @return list<string>
     */
    public static function legacy(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array{statements: list<string>, clean: bool} the statements, trimmed and non-empty, and whether the text
     *         ended cleanly: false when a quote, block comment, dollar quote or BEGIN ... END body is still open at the
     *         end, in which case the caller falls back to legacy().
     */
    public static function split(string $sql, string $dialect): array
    {
        $mysql = $dialect === self::DIALECT_MYSQL;
        $statements = [];
        $start = 0;
        $depth = 0;
        $clean = true;
        // Whether the current statement has anything but comments and whitespace. A statement of comments alone is
        // dropped: MySQL and SQLite ignore one, PostgreSQL rejects it as an empty query.
        $code = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if (!$code && $char !== ';' && !ctype_space($char) && !($char === '-' && $next === '-') && !($char === '/' && $next === '*')
                && !($mysql && $char === '#')) {
                $code = true;
            }

            // Line comments. In MySQL "--" starts one only when followed by whitespace or the end; "#" is MySQL's too.
            if (($char === '-' && $next === '-' && (!$mysql || $i + 2 >= $length || ctype_space($sql[$i + 2])))
                || ($mysql && $char === '#')) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $clean = false;
                    break;
                }
                $i = $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $i = self::endOfQuoted($sql, $i, $mysql && $char !== '`');
                if ($i >= $length) {
                    $clean = false;
                    break;
                }
                continue;
            }

            if ($dialect === self::DIALECT_POSTGRESQL && $char === '$'
                && preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i)) {
                $end = strpos($sql, $match[0], $i + strlen($match[0]));
                if ($end === false) {
                    $clean = false;
                    break;
                }
                $i = $end + strlen($match[0]) - 1;
                continue;
            }

            // Blocks count only as whole words, and only in a statement that creates a routine or trigger. BEGIN and CASE
            // open one and END closes it; END IF, END LOOP, END WHILE and END REPEAT close nothing counted.
            if (($i === 0 || !self::isWordChar($sql[$i - 1]))
                && preg_match('/\G(BEGIN|CASE|END(?:\s+(IF|LOOP|WHILE|REPEAT)\b)?)(?![A-Za-z0-9_$])/i', $sql, $match, 0, $i)
                && self::createsRoutine(substr($sql, $start, $i - $start))) {
                if (!isset($match[2])) {
                    $depth = max(0, $depth + (stripos($match[1], 'END') === 0 ? -1 : 1));
                }
                $i += strlen($match[1]) - 1;
                continue;
            }

            if ($char === ';' && $depth === 0) {
                if ($code) {
                    $statements[] = substr($sql, $start, $i - $start);
                }
                $start = $i + 1;
                $code = false;
            }
        }

        if ($clean && $depth > 0) {
            $clean = false;
        }
        if ($code) {
            $statements[] = substr($sql, $start);
        }

        return [
            'statements' => array_values(array_filter(array_map('trim', $statements), static fn (string $s): bool => $s !== '')),
            'clean' => $clean,
        ];
    }

    /**
     * The position of the quote that closes the one at $open, or the text's length when none does. A doubled quote is
     * part of the text; so is a backslash-escaped one in MySQL strings.
     */
    private static function endOfQuoted(string $sql, int $open, bool $backslashEscapes): int
    {
        $quote = $sql[$open];
        $length = strlen($sql);
        for ($i = $open + 1; $i < $length; $i++) {
            if ($backslashEscapes && $sql[$i] === '\\') {
                $i++;
                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;
                    continue;
                }

                return $i;
            }
        }

        return $length;
    }

    private static function createsRoutine(string $statement): bool
    {
        return preg_match(
            '/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',
            self::withoutLeadingComments($statement),
        ) === 1;
    }

    /** The statement without the comments and blank lines before its first word, for createsRoutine(). */
    private static function withoutLeadingComments(string $statement): string
    {
        return (string) preg_replace('/\A(?:\s+|--[^\n]*(?:\n|\z)|#[^\n]*(?:\n|\z)|\/\*.*?\*\/)*/s', '', $statement);
    }

    private static function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '$';
    }
}
