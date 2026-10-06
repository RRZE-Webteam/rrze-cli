<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Shared planning logic for CLI execution, dry-run and the interactive wizard. */
final class Preflight
{
    public static function build(array $package, string $workspace, array $options): array
    {
        $meta = $package['meta'];
        Files::memory($package['files']['tables.sql']['bytes'] * 6 + $package['files']['users.csv']['bytes'] * 8 + 16777216);
        $source = SiteAddress::parse($meta['url']);
        $target = SiteAddress::parse($options['new_url'] ?? $meta['url']);
        $tables = self::tables($workspace . '/tables.sql', $meta);
        $users = Users::plan(Users::read($workspace . '/users.csv'));
        $fields = array_values(array_unique(array_filter(array_map('trim', explode(',', $options['uid_fields'] ?? '')))));
        foreach ($fields as $field) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $field)) {
                throw new RuntimeException('Invalid numeric user-reference meta key.');
            }
        }
        $uploads = array_filter($package['files'], static fn ($name) => str_starts_with($name, 'wp-content/uploads/'), ARRAY_FILTER_USE_KEY);
        $destination = Destination::inspect($target, array_sum(array_column($uploads, 'bytes')));
        $mapping = [];
        foreach ($tables as $table) {
            $mapped = $destination['prefix'] . substr($table, strlen($meta['db_prefix']));
            if (strlen($mapped) > 64) {
                throw new RuntimeException('A mapped table name exceeds the database identifier limit.');
            }
            $mapping[$table] = $mapped;
        }
        // Use exactly the same table-reference checks and mapping as execution.
        Sql::map(file_get_contents($workspace . '/tables.sql'), $mapping);
        $report = [
            'plan_version' => 1, 'package_format_version' => $meta['format_version'],
            'source' => $source['url'], 'destination' => $target['url'],
            'action' => 'create_new_site', 'overwrite' => false,
            'destination_details' => $destination, 'tables' => $mapping,
            'users' => array_map(static fn ($row) => [
                'source_id' => (int) $row['ID'], 'login' => $row['user_login'], 'role' => $row['role'],
                'action' => $row['target_id'] === null ? 'create_wordpress_user' : 'add_site_membership',
                'target_id' => $row['target_id'],
            ], $users),
            'uploads' => ['included' => $meta['uploads_included'], 'files' => count($uploads), 'bytes' => array_sum(array_column($uploads, 'bytes'))],
            'user_reference_fields' => $fields,
            'limitations' => [
                'Site ID and available disk space are estimates, not reservations; execution repeats these checks.',
                'SQL checks cover declared site tables, not arbitrary SQL safety or successful database execution. Use controlled exports only.',
                'Database server disk space, extension compatibility and real SSO login require operational verification.',
            ],
        ];
        if (!$meta['uploads_included']) {
            $report['limitations'][] = 'Media are not included. A separate media transfer is not verified.';
        }
        return compact('meta', 'source', 'target', 'users', 'fields', 'mapping', 'destination', 'report');
    }

    public static function tables(string $filename, array $meta): array
    {
        global $wpdb;
        $sql = file_get_contents($filename);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('The SQL dump is unreadable or empty.');
        }
        $tables = Sql::tables($sql);
        if (!$tables || count($tables) !== count(array_unique($tables))) {
            throw new RuntimeException('The SQL dump has missing or duplicate table definitions.');
        }
        $prefix = $meta['db_prefix'];
        $base = $meta['blog_id'] > 1 ? preg_replace('/' . $meta['blog_id'] . '_$/D', '', $prefix) : $prefix;
        $globals = array_map(static fn ($table) => $base . $table, $wpdb->tables('global', false));
        Tables::select($tables, [], $globals, $prefix, $base, implode(',', $tables));
        $core = array_map(static fn ($table) => $prefix . $table, $wpdb->tables('blog', false));
        if (array_diff($core, $tables)) {
            throw new RuntimeException('The SQL dump is missing required site tables.');
        }
        if (isset($meta['tables']) && (!is_array($meta['tables']) || array_diff($tables, $meta['tables']) || array_diff($meta['tables'], $tables))) {
            throw new RuntimeException('The SQL table list does not match the package metadata.');
        }
        return $tables;
    }

}
