<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use RuntimeException;
use WP_CLI;

/** Imports a controlled export into a newly created multisite site. */
class Import extends Command
{
    /**
     * Imports a package into a new site. Existing sites must be deleted manually first.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : Local ZIP package.
     * [--new_url=<url>]
     * : Destination URL; defaults to the source URL.
     * [--mysql-single-transaction]
     * : Unsupported: a dump with DDL cannot be made atomic with this option.
     * [--uid_fields=<fields>]
     * : Comma-separated post meta keys containing numeric user IDs.
     * [--verbose]
     * : Show progress details.
     */
    public function all($args = [], $assoc_args = [])
    {
        global $wpdb;
        if (!is_multisite()) {
            WP_CLI::error('Migration requires a multisite destination and always creates a new site.');
        }
        $workspace = null;
        $blogId = null;
        $lock = null;
        $error = null;
        try {
            if (isset($assoc_args['mysql-single-transaction'])) {
                throw new RuntimeException('--mysql-single-transaction cannot make a migration with DDL atomic and is no longer supported.');
            }
            if (!empty($assoc_args['new_url'])) {
                $this->assert_available(SiteAddress::parse($assoc_args['new_url']));
            }
            $filename = $args[0] ?? '';
            $filename = str_starts_with($filename, '/') ? $filename : ABSPATH . $filename;
            if (!Utils::is_zip_file($filename)) {
                throw new RuntimeException('The provided file is not a readable ZIP package.');
            }
            $workspace = Files::workspace();
            WP_CLI::log('Checking migration package...');
            Utils::extract($filename, $workspace);
            $paths = [];
            foreach (['json', 'csv', 'sql'] as $extension) {
                $matches = glob($workspace . '/*.' . $extension);
                if (count($matches) !== 1 || !is_file($matches[0])) {
                    throw new RuntimeException('The package must contain exactly one metadata JSON, user CSV and SQL dump.');
                }
                $paths[$extension] = $matches[0];
            }
            if (is_dir($workspace . '/wp-content/plugins') || is_dir($workspace . '/wp-content/themes')) {
                throw new RuntimeException('Packages containing plugins or themes are not supported; provide these separately in the destination.');
            }
            $meta = json_decode(file_get_contents($paths['json']), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($meta) || !is_string($meta['url'] ?? null) || !is_string($meta['db_prefix'] ?? null)
                || !preg_match('/^[A-Za-z0-9_]+$/D', $meta['db_prefix'])
                || !is_int($meta['blog_id'] ?? null) || $meta['blog_id'] < 1) {
                throw new RuntimeException('Invalid migration metadata.');
            }
            $sourceAddress = SiteAddress::parse($meta['url']);
            $address = SiteAddress::parse($assoc_args['new_url'] ?? $meta['url']);
            $this->assert_available($address);
            $sourceTables = $this->source_tables($paths['sql'], $meta);
            $userPlan = Users::plan(Users::read($paths['csv']));
            $fields = array_values(array_filter(array_map('trim', explode(',', $assoc_args['uid_fields'] ?? ''))));
            foreach ($fields as $field) {
                if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $field)) {
                    throw new RuntimeException('Invalid numeric user-reference meta key.');
                }
            }
            // This connection holds the lock through creation and import; no force/overwrite bypass.
            $lockName = 'rrze-migration-' . substr(hash('sha256', DB_NAME . '|' . $address['domain'] . $address['path']), 0, 48);
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== 1) {
                throw new RuntimeException('Another migration is using this destination, or the destination lock is unavailable.');
            }
            $lock = $lockName;
            $this->assert_available($address);
            $existingTables = $wpdb->get_col('SHOW TABLES');
            if ($wpdb->last_error) {
                throw new RuntimeException('Could not inspect existing destination tables.');
            }
            $result = wp_insert_site(['domain' => $address['domain'], 'path' => $address['path'], 'network_id' => get_current_network_id()]);
            if (is_wp_error($result) || !$result) {
                throw new RuntimeException('Could not create a new destination site.');
            }
            $blogId = (int) $result;
            $prefix = $wpdb->get_blog_prefix($blogId);
            $targetTables = array_map(static fn ($table) => $prefix . substr($table, strlen($meta['db_prefix'])), $sourceTables);
            if (array_intersect($targetTables, $existingTables)) {
                throw new RuntimeException('The new site would collide with pre-existing tables. Import stopped.');
            }
            WP_CLI::log('Importing tables into new site ' . $blogId . '...');
            $this->import_tables($paths['sql'], $sourceTables, $targetTables, $meta, $sourceAddress, $address, $blogId);
            $ids = $this->import_users($userPlan, $blogId);
            Posts::remap($blogId, $ids, $fields);
            if (is_dir($workspace . '/wp-content/uploads')) {
                $this->move_uploads($workspace . '/wp-content/uploads', $blogId);
            }
            switch_to_blog($blogId);
            try {
                flush_rewrite_rules(false);
                if ($wpdb->last_error) {
                    throw new RuntimeException('Could not flush rewrite rules for the new site.');
                }
            } finally {
                restore_current_blog();
            }
        } catch (\Throwable $failure) {
            $error = $failure->getMessage();
        } finally {
            if ($lock !== null) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            if ($workspace !== null) {
                try {
                    Files::remove($workspace);
                } catch (\Throwable $failure) {
                    $error = ($error ? $error . ' ' : '') . 'Could not clean up the private migration workspace: ' . $workspace;
                }
            }
        }
        if ($error !== null) {
            if ($blogId !== null) {
                $error .= ' New site ID ' . $blogId . ' may be incomplete. Inspect it and delete it manually in Network Admin before retrying; it was not automatically removed.';
            }
            WP_CLI::error($error);
        }
        WP_CLI::success('All done, your new site is available at ' . $address['url']);
    }

    /** Direct imports are disabled; only import all can establish ownership of a new site. */
    public function users($args = [], $assoc_args = [])
    {
        WP_CLI::error('Direct user imports are disabled. Use rrze-migration import all to create a new site.');
    }

    /** Direct imports are disabled; only import all can establish ownership of a new site. */
    public function tables($args = [], $assoc_args = [])
    {
        WP_CLI::error('Direct table imports are disabled. Use rrze-migration import all to create a new site.');
    }

    private function assert_available(array $address): void
    {
        global $wpdb;
        // Query all networks and all statuses, including deleted/archived entries.
        $existing = $wpdb->get_var($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s LIMIT 1", $address['domain'], $address['path']));
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not check whether the destination exists.');
        }
        if ($existing !== null) {
            throw new RuntimeException('The destination site already exists. Delete it manually in Network Admin before importing. Existing sites are never overwritten.');
        }
    }

    private function source_tables(string $filename, array $meta): array
    {
        global $wpdb;
        $sql = file_get_contents($filename);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('The SQL dump is unreadable or empty.');
        }
        preg_match_all('/^CREATE TABLE(?: IF NOT EXISTS)? `([A-Za-z0-9_]+)`/m', $sql, $matches);
        $tables = $matches[1];
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

    private function import_tables(string $filename, array $sourceTables, array $targetTables, array $meta, array $source, array $target, int $blogId): void
    {
        global $wpdb;
        $mapping = array_combine($sourceTables, $targetTables);
        $sql = preg_replace_callback('/\b(DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|LOCK TABLES|INSERT INTO|ALTER TABLE|REFERENCES)\s+`([^`]+)`/', static function ($match) use ($mapping) {
            if (!isset($mapping[$match[2]])) {
                throw new RuntimeException('The SQL dump refers to a table outside the source site.');
            }
            return $match[1] . ' `' . $mapping[$match[2]] . '`';
        }, file_get_contents($filename));
        Files::write($filename, $sql);
        Utils::checked_command('db import', [$filename]);
        Utils::checked_command('search-replace', [Utils::parse_url_for_search_replace($source['url']), Utils::parse_url_for_search_replace($target['url']), ...$targetTables], ['precise' => true], ['url' => $target['url']]);
        $from = 'wp-content/uploads' . ($meta['blog_id'] > 1 ? '/sites/' . $meta['blog_id'] : '');
        $to = 'wp-content/uploads/sites/' . $blogId;
        Utils::checked_command('search-replace', [$from, $to, ...$targetTables], ['precise' => true], ['url' => $target['url']]);
        switch_to_blog($blogId);
        try {
            if ($wpdb->update($wpdb->options, ['option_name' => $wpdb->prefix . 'user_roles'], ['option_name' => $meta['db_prefix'] . 'user_roles']) === false) {
                throw new RuntimeException('Could not map the new site role option.');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            foreach (['home', 'siteurl'] as $option) {
                update_option($option, untrailingslashit($target['url']));
                if (get_option($option) !== untrailingslashit($target['url']) || $wpdb->last_error) {
                    throw new RuntimeException('Could not set the destination URL.');
                }
            }
        } finally {
            restore_current_blog();
        }
        Utils::checked_command('transient delete', [], ['all' => true], ['url' => $target['url']]);
    }

    private function import_users(array $plan, int $blogId): array
    {
        // Re-check the whole identity plan immediately before writing any users.
        $plan = Users::plan($plan);
        $ids = [];
        switch_to_blog($blogId);
        try {
            wp_roles()->for_site($blogId);
            foreach ($plan as $row) {
                $id = $row['target_id'];
                if ($id === null) {
                    $data = array_intersect_key($row, array_flip(array_diff(Users::HEADERS, ['ID'])));
                    $data['user_pass'] = wp_generate_password(64, true, true);
                    $id = wp_insert_user($data);
                    if (is_wp_error($id)) {
                        throw new RuntimeException('Could not create a WordPress user for an SSO identity.');
                    }
                } else {
                    // Unlike add_user_to_blog(), this does not change primary_blog/source_domain.
                    $user = new \WP_User($id, '', $blogId);
                    $user->set_role($row['role']);
                }
                $user = new \WP_User($id, '', $blogId);
                if (!in_array($row['role'], $user->roles, true)) {
                    throw new RuntimeException('Could not assign the user role on the new site.');
                }
                $ids[(int) $row['ID']] = (int) $id;
            }
        } finally {
            restore_current_blog();
        }
        return $ids;
    }

    private function move_uploads(string $source, int $blogId): void
    {
        switch_to_blog($blogId);
        try {
            $uploads = wp_upload_dir();
            if ($uploads['error']) {
                throw new RuntimeException('Cannot prepare the new site upload directory.');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $relative = substr($item->getPathname(), strlen($source) + 1);
                $destination = $uploads['basedir'] . '/' . $relative;
                if ($item->isLink() || is_link($destination)) {
                    throw new RuntimeException('Symbolic links in migration uploads are not supported.');
                }
                if ($item->isDir()) {
                    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                        throw new RuntimeException('Cannot create an upload directory.');
                    }
                } else {
                    if (file_exists($destination) || !rename($item->getPathname(), $destination)) {
                        throw new RuntimeException('An upload file already exists or could not be moved.');
                    }
                }
            }
        } finally {
            restore_current_blog();
        }
    }
}
