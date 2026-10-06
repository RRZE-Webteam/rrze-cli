<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Table mapping for controlled mysqldump output; deliberately not a general SQL sandbox. */
final class Sql
{
    private static function structure(string $sql): string
    {
        // Scan byte spans instead of applying a recursive regex to potentially huge values.
        // Masked spans retain their byte lengths, so table offsets still address the original SQL.
        $length = strlen($sql);
        $structure = '';
        $copied = $position = 0;
        $executableEnd = null;
        while ($position < $length) {
            $limit = $executableEnd ?? $length;
            $position += strcspn($sql, "'\"`/#-", $position, $limit - $position);
            if ($position === $limit) {
                if ($executableEnd !== null) {
                    $position += 2;
                    $executableEnd = null;
                    continue;
                }
                break;
            }
            $start = $position;
            $char = $sql[$position];
            if ($char === "'" || $char === '"' || $char === '`') {
                $position = self::quotedEnd($sql, $position, $limit);
                if ($char === '`') {
                    // Preserve quoted identifiers; quotes/comment markers inside them are literal.
                    continue;
                }
            } elseif ($char === '/' && ($sql[$position + 1] ?? '') === '*') {
                if ($executableEnd !== null) {
                    throw new RuntimeException('Nested SQL comments are unsupported at byte ' . $start . '.');
                }
                $end = strpos($sql, '*/', $position + 2);
                if ($end === false) {
                    throw new RuntimeException('Unterminated SQL comment at byte ' . $start . '.');
                }
                if (($sql[$position + 2] ?? '') === '!') {
                    // MySQL version comments can execute SQL; keep their table references visible.
                    $executableEnd = $end;
                    $position += 3;
                    continue;
                }
                $position = $end + 2;
            } elseif ($char === '#' || ($char === '-' && ($sql[$position + 1] ?? '') === '-'
                && (!isset($sql[$position + 2]) || ord($sql[$position + 2]) <= 32 || ord($sql[$position + 2]) === 127))) {
                // MySQL requires whitespace/control after --; subtraction such as 1--1 is not a comment.
                $position += strcspn($sql, "\r\n", $position, $limit - $position);
            } else {
                $position++;
                continue;
            }
            $structure .= substr($sql, $copied, $start - $copied) . str_repeat(' ', $position - $start);
            $copied = $position;
        }
        return $structure . substr($sql, $copied);
    }

    private static function quotedEnd(string $sql, int $start, int $limit): int
    {
        $quote = $sql[$start];
        $special = $quote === '`' ? '`' : $quote . '\\';
        $position = $start + 1;
        while ($position < $limit) {
            $position += strcspn($sql, $special, $position, $limit - $position);
            if ($position === $limit) {
                break;
            }
            if ($sql[$position] === '\\' || ($position + 1 < $limit && $sql[$position + 1] === $quote)) {
                $position += 2;
            } else {
                return $position + 1;
            }
        }
        throw new RuntimeException('Unterminated quoted SQL token at byte ' . $start . '.');
    }

    public static function tables(string $sql): array
    {
        if (preg_match_all('/\bCREATE TABLE(?: IF NOT EXISTS)?\s+`([A-Za-z0-9_]+)`/i', self::structure($sql), $matches) === false) {
            throw new RuntimeException('Could not inspect SQL table definitions: ' . preg_last_error_msg() . '.');
        }
        return $matches[1];
    }

    public static function map(string $sql, array $mapping): string
    {
        if (preg_match_all('/\b(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|LOCK TABLES|INSERT INTO|ALTER TABLE|REFERENCES)\s+`([^`]+)`/i', self::structure($sql), $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('Could not inspect SQL table references: ' . preg_last_error_msg() . '.');
        }
        $mapped = '';
        $copied = 0;
        foreach ($matches[1] as [$table, $offset]) {
            if (!isset($mapping[$table])) {
                throw new RuntimeException('The SQL dump refers to a table outside the source site.');
            }
            $mapped .= substr($sql, $copied, $offset - $copied) . $mapping[$table];
            $copied = $offset + strlen($table);
        }
        return $mapped . substr($sql, $copied);
    }
}
