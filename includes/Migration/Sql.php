<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Table mapping for controlled mysqldump output; deliberately not a general SQL sandbox. */
final class Sql
{
    private static function structure(string $sql): string
    {
        // Preserve offsets, but hide quoted values and non-executable comments from table matching.
        $pattern = '~\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|\\\\.|[^"\\\\])*"|--[^\r\n]*|\#[^\r\n]*|/\*(?!\!)[\s\S]*?\*/~';
        $structure = preg_replace_callback($pattern, static fn ($match) => str_repeat(' ', strlen($match[0])), $sql);
        if ($structure === null) {
            throw new RuntimeException('Could not inspect SQL structure within parser limits.');
        }
        return $structure;
    }

    public static function tables(string $sql): array
    {
        preg_match_all('/\bCREATE TABLE(?: IF NOT EXISTS)?\s+`([A-Za-z0-9_]+)`/i', self::structure($sql), $matches);
        return $matches[1];
    }

    public static function map(string $sql, array $mapping): string
    {
        preg_match_all('/\b(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|LOCK TABLES|INSERT INTO|ALTER TABLE|REFERENCES)\s+`([^`]+)`/i', self::structure($sql), $matches, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($matches[1]) as [$table, $offset]) {
            if (!isset($mapping[$table])) {
                throw new RuntimeException('The SQL dump refers to a table outside the source site.');
            }
            $sql = substr_replace($sql, $mapping[$table], $offset, strlen($table));
        }
        return $sql;
    }
}
