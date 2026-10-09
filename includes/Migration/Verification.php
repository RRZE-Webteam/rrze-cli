<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

final class Verification
{
    public static function users(array $plan, ?int $siteId = null): array
    {
        global $wpdb;
        $hashes = [];
        foreach ($plan as $row) {
            $id = $row['target_id'];
            if ($id === null) {
                continue;
            }
            $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $id), ARRAY_A);
            if ($wpdb->last_error || !$user) {
                throw new RuntimeException('Could not verify an existing global user.');
            }
            $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $id), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException('Could not verify existing global user metadata.');
            }
            if ($siteId !== null && Users::isMember($row)) {
                $prefix = $wpdb->get_blog_prefix($siteId);
                $meta = array_values(array_filter($meta, static fn ($item) => !in_array($item['meta_key'], [$prefix . 'capabilities', $prefix . 'user_level'], true)));
            }
            $hashes[(int) $id] = hash('sha256', json_encode([$user, $meta], JSON_THROW_ON_ERROR));
        }
        return $hashes;
    }

    public static function site(int $id, string $runId, array $target): void
    {
        global $wpdb;
        // Bypass per-process caches so deletion or replacement by another process is visible.
        $site = $wpdb->get_row($wpdb->prepare("SELECT domain, path, site_id FROM {$wpdb->blogs} WHERE blog_id = %d", $id));
        if ($wpdb->last_error) {
            throw new RuntimeException('Cannot verify destination ownership.');
        }
        $owner = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->blogmeta} WHERE blog_id = %d AND meta_key = 'rrze_migration_run'", $id));
        if (!$site || $site->domain !== $target['domain'] || $site->path !== $target['path']
            || (int) $site->site_id !== get_current_network_id() || $wpdb->last_error || $owner !== $runId) {
            throw new RuntimeException('The destination no longer belongs to this migration run.');
        }
    }

    public static function result(array $plan, array $package, int $id, string $runId, array $ids, array $baseline): void
    {
        global $wpdb;
        self::site($id, $runId, $plan['target']);
        if (self::users($plan['users'], $id) !== $baseline) {
            throw new RuntimeException('An existing global user changed beyond the new site membership. Review the run and independent backups.');
        }
        $tables = $wpdb->get_col('SHOW TABLES');
        if ($wpdb->last_error || array_diff(array_values($plan['mapping']), $tables)) {
            throw new RuntimeException('Destination table verification failed.');
        }
        switch_to_blog($id);
        try {
            foreach (['home', 'siteurl'] as $option) {
                if (get_option($option) !== untrailingslashit($plan['target']['url'])) {
                    throw new RuntimeException('Destination URL verification failed.');
                }
            }
            foreach ($plan['users'] as $user) {
                $target = new \WP_User($ids[(int) $user['ID']], '', $id);
                if ($target->user_login !== $user['user_login'] || strcasecmp($target->user_email, $user['user_email']) !== 0
                    || (Users::isMember($user) ? !in_array($user['role'], $target->roles, true)
                        : metadata_exists('user', $target->ID, $wpdb->prefix . 'capabilities') || metadata_exists('user', $target->ID, $wpdb->prefix . 'user_level'))) {
                    throw new RuntimeException('Destination user or membership verification failed.');
                }
            }
            foreach ([$wpdb->posts => 'post_author', $wpdb->comments => 'user_id'] as $table => $column) {
                $values = $wpdb->get_col("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` <> 0");
                if ($wpdb->last_error || array_diff(array_map('intval', $values), array_values($ids))) {
                    throw new RuntimeException('Destination user reference verification failed.');
                }
            }
        } finally {
            restore_current_blog();
        }
    }
}
