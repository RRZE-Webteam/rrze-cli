<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Selects site-owned tables; a main-site prefix alone is not proof of ownership. */
final class Tables
{
    public static function select(array $available, array $core, array $global, string $prefix, string $basePrefix, string $requested = '', string $custom = ''): array
    {
        $allowed = static function (string $table) use ($available, $global, $prefix, $basePrefix): bool {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !in_array($table, $available, true)
                || in_array($table, $global, true) || !str_starts_with($table, $prefix)) {
                return false;
            }
            // wp_ also prefixes wp_2_posts, wp_3_options, etc.
            return $prefix !== $basePrefix || !preg_match('/^[0-9]+_/', substr($table, strlen($basePrefix)));
        };
        $parse = static function (string $list) use ($allowed): array {
            if ($list === '') {
                return [];
            }
            $tables = array_map('trim', explode(',', $list));
            foreach ($tables as $table) {
                if (!$allowed($table)) {
                    throw new RuntimeException('A requested table is missing or is not owned by the source site.');
                }
            }
            return $tables;
        };
        // Ambiguous main-site custom tables must be explicitly selected.
        $defaults = $prefix === $basePrefix ? $core : array_values(array_filter($available, $allowed));
        $selected = array_values(array_unique(array_merge($requested === '' ? $defaults : $parse($requested), $parse($custom))));
        foreach ($selected as $table) {
            if (!$allowed($table)) {
                throw new RuntimeException('A required source table is missing or unsafe.');
            }
        }
        if ($selected === []) {
            throw new RuntimeException('No site-owned tables selected.');
        }
        sort($selected);
        return $selected;
    }
}
