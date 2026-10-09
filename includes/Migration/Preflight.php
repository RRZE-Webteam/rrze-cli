<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Shared planning logic for CLI execution, dry-run and the interactive wizard. */
final class Preflight
{
    public const MEDIA_NOTICE = 'Media are transferred by the administrator with rsync. The import prepares URL mappings and a transfer plan; the run remains media_pending until media verify succeeds.';

    public static function build(array $package, string $workspace, array $options): array
    {
        if (array_key_exists('skip-uploads', $options) || array_key_exists('uploads', $options)) {
            throw new RuntimeException('The upload options have been removed. Media are always transferred externally with rsync.');
        }
        $meta = $package['meta'];
        Files::memory($package['files']['tables.sql']['bytes'] * 6 + $package['files']['users.csv']['bytes'] * 8 + 16777216);
        $source = SiteAddress::parse($meta['url']);
        $target = SiteAddress::parse($options['new_url'] ?? $meta['url']);
        $tables = self::tables($workspace . '/tables.sql', $meta);
        $users = Users::plan(Users::read($workspace . '/users.csv'));
        Users::requireReferences(Sql::userReferences(file_get_contents($workspace . '/tables.sql'), $meta['db_prefix']), $users);
        $fields = array_values(array_unique(array_filter(array_map('trim', explode(',', $options['uid_fields'] ?? '')))));
        foreach ($fields as $field) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $field)) {
                throw new RuntimeException('Invalid numeric user-reference meta key.');
            }
        }
        $media = $package['media'];
        $uploads = $media['files'];
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
            'plan_version' => 2, 'package_format_version' => $meta['format_version'],
            'source' => $source['url'], 'destination' => $target['url'],
            'action' => 'create_new_site', 'overwrite' => false,
            'destination_details' => $destination, 'tables' => $mapping,
            'users' => array_map(static fn ($row) => [
                'source_id' => (int) $row['ID'], 'login' => $row['user_login'], 'role' => $row['role'],
                'site_member' => Users::isMember($row),
                'action' => $row['target_id'] === null ? 'create_wordpress_user' : (Users::isMember($row) ? 'add_site_membership' : 'map_existing_user'),
                'target_id' => $row['target_id'],
            ], $users),
            'uploads' => ['transport' => 'rsync', 'verified' => false, 'status' => 'pending',
                'manual_transfer_required' => true, 'source_directory' => $media['source_directory'],
                'source_baseurl' => $media['source_baseurl'],
                'files' => count($uploads), 'bytes' => array_sum(array_column($uploads, 'bytes')),
                'excluded_directories' => $media['excluded_directories']],
            'user_reference_fields' => $fields,
            'limitations' => [
                'Site ID and available disk space are estimates, not reservations; execution repeats these checks.',
                'SQL checks cover declared site tables, not arbitrary SQL safety or successful database execution. Use controlled exports only.',
                'Database server disk space, extension compatibility and real SSO login require operational verification.',
            ],
        ];
        $report['limitations'][] = self::MEDIA_NOTICE;
        if ($report['uploads']['excluded_directories']) {
            $report['limitations'][] = 'Explicitly excluded upload directories are not transferred or verified.';
        }
        return compact('meta', 'source', 'target', 'users', 'fields', 'mapping', 'destination', 'report', 'media');
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
