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

    /** Read core user IDs from controlled mysqldump rows, without executing the dump. */
    public static function userReferences(string $sql, string $prefix): array
    {
        $structure = self::structure($sql);
        $ids = [];
        foreach (['posts' => 'post_author', 'comments' => 'user_id'] as $suffix => $column) {
            $table = preg_quote($prefix . $suffix, '/');
            if (preg_match_all('/\bCREATE TABLE(?: IF NOT EXISTS)?\s+`' . $table . '`\s*(?=\()/i', $structure, $definitions, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Cannot inspect the SQL schema for core user references.');
            }
            [$definition, $position] = $definitions[0][0];
            $position += strlen($definition);
            $columns = [];
            foreach (self::tuple($structure, $position) as [$start, $length]) {
                if (preg_match('/^\s*`([A-Za-z0-9_]+)`\s+/', substr($structure, $start, $length), $match)) {
                    $columns[] = $match[1];
                }
            }
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException('The SQL schema is missing a core user-reference column.');
            }
            if (preg_match_all('/\bINSERT\s+INTO\s+`' . $table . '`(?=\s|\()/i', $structure, $inserts, PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException('Cannot inspect SQL inserts for core user references.');
            }
            foreach ($inserts[0] as [$insert, $position]) {
                $position += strlen($insert);
                self::whitespace($structure, $position);
                $order = $columns;
                if (($structure[$position] ?? '') === '(') {
                    $order = [];
                    foreach (self::tuple($structure, $position) as [$start, $length]) {
                        if (!preg_match('/^\s*`([A-Za-z0-9_]+)`\s*$/D', substr($structure, $start, $length), $match)) {
                            throw new RuntimeException('Unsupported SQL column list for core user references.');
                        }
                        $order[] = $match[1];
                    }
                }
                $index = array_search($column, $order, true);
                self::whitespace($structure, $position);
                if ($index === false || count($order) !== count(array_unique($order)) || array_diff($order, $columns)
                    || preg_match('/\GVALUES\b\s*/i', $structure, $match, 0, $position) !== 1) {
                    throw new RuntimeException('Unsupported SQL insert for core user references.');
                }
                $position += strlen($match[0]);
                do {
                    $values = self::tuple($structure, $position);
                    if (count($values) !== count($order)) {
                        throw new RuntimeException('SQL row length does not match its user-reference schema.');
                    }
                    [$start, $length] = $values[$index];
                    $value = trim(substr($sql, $start, $length));
                    if (!preg_match('/^(?:[0-9]+|\'[0-9]+\'|"[0-9]+")$/D', $value)) {
                        throw new RuntimeException('A core SQL user reference is not a supported numeric ID.');
                    }
                    $value = ltrim(trim($value, '\'"'), '0');
                    $id = (int) $value;
                    if ($value !== '' && (string) $id !== $value) {
                        throw new RuntimeException('A core SQL user reference exceeds the supported ID range.');
                    }
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                    self::whitespace($structure, $position);
                    $separator = $structure[$position++] ?? '';
                    self::whitespace($structure, $position);
                } while ($separator === ',');
                if ($separator !== ';') {
                    throw new RuntimeException('Unsupported SQL row terminator for core user references.');
                }
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Byte spans of comma-separated items; quoted values were already masked. */
    private static function tuple(string $structure, int &$position): array
    {
        if (($structure[$position] ?? '') !== '(') {
            throw new RuntimeException('Expected a SQL tuple while inspecting core user references.');
        }
        $length = strlen($structure);
        $start = ++$position;
        $depth = 1;
        $parts = [];
        while ($position < $length) {
            $position += strcspn($structure, '(),', $position);
            $token = $structure[$position] ?? '';
            if ($token === '(' && ++$depth > 32) {
                throw new RuntimeException('SQL tuple nesting exceeds the inspection limit.');
            }
            if ($token === ')') {
                $depth--;
            }
            if ($depth === 0 || ($token === ',' && $depth === 1)) {
                $parts[] = [$start, $position - $start];
                $start = $position + 1;
            }
            $position++;
            if ($depth === 0) {
                return $parts;
            }
        }
        throw new RuntimeException('Unterminated SQL tuple while inspecting core user references.');
    }

    private static function whitespace(string $structure, int &$position): void
    {
        $position += strspn($structure, " \t\r\n", $position);
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
