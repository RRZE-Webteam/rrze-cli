<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Read-only checks reused for planning, execution and before WordPress initializes a new ID. */
final class Destination
{
    public static function available(array $address): void
    {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s LIMIT 1", $address['domain'], $address['path']));
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not check whether the destination exists.');
        }
        if ($existing !== null) {
            throw new RuntimeException('The destination site already exists. Delete it manually in Network Admin before importing. Existing sites are never overwritten.');
        }
    }

    public static function inspect(array $address, int $uploadBytes): array
    {
        global $wpdb;
        self::available($address);
        $revokes = $wpdb->get_row("SHOW VARIABLES LIKE 'partial_revokes'");
        if ($wpdb->last_error) {
            throw new RuntimeException('Cannot inspect database grant semantics.');
        }
        $grants = $wpdb->get_col('SHOW GRANTS FOR CURRENT_USER()');
        if ($wpdb->last_error || Privileges::missing($grants, DB_NAME, strtoupper($revokes->Value ?? '') === 'ON')) {
            throw new RuntimeException('Cannot verify the required database permissions (explicit schema grants required).');
        }
        $id = self::nextSiteId();
        $uploads = self::resources($id);
        return ['network_id' => get_current_network_id(), 'estimated_site_id' => $id,
            'prefix' => $wpdb->get_blog_prefix($id), 'uploads_directory' => $uploads,
            'storage' => Files::capacity($uploads, $uploadBytes + 16777216)];
    }

    /** An estimate, rechecked under the lock and against the ID WordPress allocates. */
    public static function nextSiteId(): int
    {
        global $wpdb;
        // MySQL 8 caches AUTO_INCREMENT in table statistics. A stale value can
        // point at an existing site's tables even in a fresh WP-CLI process.
        // Older MySQL/MariaDB versions without this variable need no override.
        $setting = $wpdb->get_row("SHOW SESSION VARIABLES LIKE 'information_schema_stats_expiry'");
        if ($wpdb->last_error || ($setting !== null && !ctype_digit((string) ($setting->Value ?? '')))) {
            throw new RuntimeException('Cannot inspect the database metadata cache setting.');
        }
        $expiry = $setting === null ? 0 : (int) $setting->Value;
        if ($expiry !== 0 && $wpdb->query('SET SESSION information_schema_stats_expiry = 0') === false) {
            throw new RuntimeException('Cannot read uncached destination site IDs.');
        }
        try {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->blogs));
            if ($wpdb->last_error || !$status || (int) ($status->Auto_increment ?? 0) < 2) {
                throw new RuntimeException('Cannot determine the next destination site ID.');
            }
            return (int) $status->Auto_increment;
        } finally {
            if ($expiry !== 0 && $wpdb->query('SET SESSION information_schema_stats_expiry = ' . $expiry) === false) {
                throw new RuntimeException('Cannot restore the database metadata cache setting.');
            }
        }
    }

    public static function uploadPath(int $id): string
    {
        $main = get_main_site_id();
        $custom = get_blog_option($main, 'upload_path');
        if (!defined('MULTISITE') || defined('UPLOADS') || defined('BLOGUPLOADDIR')
            || get_site_option('ms_files_rewriting')
            || ($custom && $custom !== 'wp-content/uploads')) {
            throw new UnsupportedUploadLayout('Custom destination upload roots and legacy multisite uploads require a supported migration adapter. External transfer does not make a shared destination directory safe.');
        }
        $raw = _wp_upload_dir();
        $effective = wp_upload_dir(null, false, true);
        if (!empty($effective['error']) || ($effective['basedir'] ?? null) !== $raw['basedir']) {
            throw new UnsupportedUploadLayout('The upload filter changes the destination media root. A dedicated site-owned destination adapter is required.');
        }
        return self::standardUploadPath($id);
    }

    private static function standardUploadPath(int $id): string
    {
        $content = realpath(WP_CONTENT_DIR);
        if ($content === false) {
            throw new RuntimeException('Cannot resolve the destination content directory.');
        }
        $path = $content;
        foreach (['uploads', 'sites', (string) $id] as $part) {
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                throw new RuntimeException('A destination upload parent is a link or is not a directory.');
            }
        }
        return $path;
    }

    public static function resources(int $id): string
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix($id);
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not inspect destination tables.');
        }
        if ($tables) {
            throw new RuntimeException('Pre-existing tables for the new site ID block migration. Inspect the leftovers manually.');
        }
        $memberships = $wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) LIMIT 1", $prefix . 'capabilities', $prefix . 'user_level'));
        if ($wpdb->last_error || $memberships !== null) {
            throw new RuntimeException('Pre-existing membership metadata for the new site ID blocks migration.');
        }
        $uploads = self::uploadPath($id);
        if (file_exists($uploads) || is_link($uploads)) {
            throw new RuntimeException('Pre-existing uploads for the new site ID block migration. Inspect the leftovers manually.');
        }
        return $uploads;
    }
}
