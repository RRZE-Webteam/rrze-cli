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

    public static function inspect(array $address, int $uploadBytes, bool $skipUploads = false): array
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
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->blogs));
        if ($wpdb->last_error || !$status || (int) $status->Auto_increment < 2) {
            throw new RuntimeException('Cannot determine the next destination site ID.');
        }
        $id = (int) $status->Auto_increment;
        $uploads = self::resources($id, $skipUploads);
        return ['network_id' => get_current_network_id(), 'estimated_site_id' => $id,
            'prefix' => $wpdb->get_blog_prefix($id), 'uploads_directory' => $uploads,
            'storage' => $skipUploads ? null : Files::capacity($uploads, $uploadBytes + 16777216)];
    }

    public static function uploadPath(int $id): string
    {
        $main = get_main_site_id();
        $custom = get_blog_option($main, 'upload_path');
        if (!defined('MULTISITE') || defined('UPLOADS') || defined('BLOGUPLOADDIR')
            || get_site_option('ms_files_rewriting') || has_filter('upload_dir')
            || ($custom && $custom !== 'wp-content/uploads')) {
            throw new UnsupportedUploadLayout('Custom upload paths, upload filters and legacy multisite uploads require a separately supported migration adapter. Use --skip-uploads only if media will be transferred and configured manually.');
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

    public static function resources(int $id, bool $skipUploads = false): ?string
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
        // Even manual transfers cannot bypass known leftovers or links in the standard location.
        // A custom location is deliberately not resolved, created or written by this importer.
        $uploads = $skipUploads ? self::standardUploadPath($id) : self::uploadPath($id);
        if (file_exists($uploads) || is_link($uploads)) {
            throw new RuntimeException('Pre-existing uploads for the new site ID block migration. Inspect the leftovers manually.');
        }
        return $skipUploads ? null : $uploads;
    }
}
