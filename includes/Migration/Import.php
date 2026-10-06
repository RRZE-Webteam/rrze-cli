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
     * [--dry-run]
     * : Validate and display the migration plan without writing to the destination.
     * [--format=<format>]
     * : Dry-run output: text (default) or json.
     * [--run-dir=<directory>]
     * : Private persistent run directory outside web roots; or set RRZE_MIGRATION_RUN_DIR.
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
        $run = null;
        $execution = null;
        $dryRun = isset($assoc_args['dry-run']);
        $error = null;
        try {
            if (isset($assoc_args['mysql-single-transaction'])) {
                throw new RuntimeException('--mysql-single-transaction cannot make a migration with DDL atomic and is no longer supported.');
            }
            if (!empty($assoc_args['new_url'])) {
                Destination::available(SiteAddress::parse($assoc_args['new_url']));
            }
            $filename = $args[0] ?? '';
            $filename = str_starts_with($filename, '/') ? $filename : ABSPATH . $filename;
            if (!$dryRun) {
                $root = Run::root($assoc_args['run-dir'] ?? (defined('RRZE_MIGRATION_RUN_DIR') ? RRZE_MIGRATION_RUN_DIR : ''), [ABSPATH, WP_CONTENT_DIR], true);
                $run = Run::create($root);
                WP_CLI::log('Migration run: ' . $run->id);
                $run->begin('prepare');
                $filename = $run->package($filename);
            }
            $workspace = Files::workspace();
            $run?->workspace($workspace);
            $format = $assoc_args['format'] ?? 'text';
            if (!in_array($format, ['text', 'json'], true) || (!$dryRun && $format !== 'text')) {
                throw new RuntimeException('--format accepts text or json; JSON output requires --dry-run.');
            }
            if (!$dryRun || $format === 'text') {
                WP_CLI::log('Checking migration package...');
            }
            $package = Package::read($filename, $workspace);
            $plan = Preflight::build($package, $workspace, $assoc_args);
            $address = $plan['target'];
            if (!$dryRun) {
                $run->plan($plan, hash('sha256', DB_NAME . '|' . $wpdb->base_prefix));
                $run->done();
                $run->begin('acquire_lock');
                $execution = new Execution($run);
                $run->done();
                $plan = $execution->step('recheck', static fn () => Preflight::build($package, $workspace, $assoc_args));
                $run->plan($plan, hash('sha256', DB_NAME . '|' . $wpdb->base_prefix));
                $baseline = Verification::users($plan['users']);
                $run->baseline($baseline);
                $execution->step('create_site', function () use (&$blogId, $plan, $address, $run): void {
                    $expectedId = $plan['destination']['estimated_site_id'];
                    $guard = static function ($site) use (&$blogId, $expectedId, $run, $address): void {
                        global $wpdb;
                        $blogId = (int) $site->id;
                        $run->site($blogId);
                        if ($blogId !== $expectedId || $site->domain !== $address['domain'] || $site->path !== $address['path']) {
                            throw new RuntimeException('The destination site allocation changed after preflight. Import stopped before initialization.');
                        }
                        $others = $wpdb->get_var($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s AND blog_id <> %d LIMIT 1", $address['domain'], $address['path'], $blogId));
                        if ($wpdb->last_error || $others !== null) {
                            throw new RuntimeException('Another site claimed the destination during site allocation.');
                        }
                        Destination::resources($blogId);
                        if (!add_site_meta($blogId, 'rrze_migration_run', $run->id, true)) {
                            throw new RuntimeException('Cannot mark the newly created site as owned by this run.');
                        }
                    };
                    add_action('wp_insert_site', $guard, PHP_INT_MIN);
                    try {
                        $result = wp_insert_site(['domain' => $address['domain'], 'path' => $address['path'], 'network_id' => get_current_network_id()]);
                    } finally {
                        remove_action('wp_insert_site', $guard, PHP_INT_MIN);
                    }
                    if (is_wp_error($result) || !$result) {
                        throw new RuntimeException('Could not create a new destination site.');
                    }
                    $blogId = (int) $result;
                });
                $owned = static fn () => Verification::site($blogId, $run->id, $address);
                WP_CLI::log('Importing into new site ' . $blogId . '...');
                $execution->step('import_tables', fn () => $this->import_tables($workspace . '/tables.sql', array_keys($plan['mapping']), array_values($plan['mapping'])), $owned);
                $execution->step('replace_urls', fn () => $this->replace_urls(array_values($plan['mapping']), $plan['meta'], $plan['source'], $address, $blogId), $owned);
                $execution->step('configure_site', fn () => $this->configure_site($plan['meta'], $address, $blogId), $owned);
                $ids = $execution->step('import_users', fn () => $this->import_users($plan['users'], $blogId, $execution), $owned);
                $execution->step('remap_references', fn () => Posts::remap($blogId, $ids, $plan['fields']), $owned);
                $execution->step('import_uploads', function () use ($workspace, $blogId, $plan, $execution): void {
                    if (is_dir($workspace . '/wp-content/uploads')) {
                        $this->move_uploads($workspace . '/wp-content/uploads', $blogId, $plan['destination']['uploads_directory'], $execution);
                    }
                }, $owned);
                $execution->step('finalize', static function () use ($blogId): void {
                    global $wpdb;
                    switch_to_blog($blogId);
                    try {
                        flush_rewrite_rules(false);
                        if ($wpdb->last_error) {
                            throw new RuntimeException('Could not flush rewrite rules for the new site.');
                        }
                    } finally {
                        restore_current_blog();
                    }
                }, $owned);
                $execution->step('verify', static fn () => Verification::result($plan, $package, $blogId, $run->id, $ids, $baseline), $owned);
            }
        } catch (\Throwable $failure) {
            $error = $failure->getMessage();
            if ($run !== null) {
                try { $run->failure(); } catch (\Throwable $ignored) { $error .= ' Could not persist the failure checkpoint.'; }
            }
        } finally {
            if ($workspace !== null) {
                try {
                    $run?->begin('cleanup');
                    Files::remove($workspace);
                    $run?->done();
                } catch (\Throwable $failure) {
                    $error = ($error ? $error . ' ' : '') . 'Could not clean up the private migration workspace: ' . $workspace;
                }
            }
            try {
                $execution?->close();
            } catch (\Throwable $failure) {
                $error = ($error ? $error . ' ' : '') . 'Could not release the migration lock.';
            }
            if ($run !== null) {
                try {
                    $run->finish($execution?->cancelled() ? 'interrupted' : ($error === null ? 'completed' : 'failed'));
                } catch (\Throwable $failure) {
                    $error = ($error ? $error . ' ' : '') . 'Could not persist the final migration status.';
                }
            }
        }
        if ($error !== null) {
            if ($blogId !== null) {
                $error .= ' New site ID ' . $blogId . ' may be incomplete. Inspect it and delete it manually in Network Admin before retrying; it was not automatically removed.';
            }
            if ($run !== null) {
                $error .= ' Run ID: ' . $run->id . '. Read its status before recovery.';
            }
            WP_CLI::error($error);
        }
        if ($dryRun) {
            if ($format === 'json') {
                WP_CLI::line(json_encode($plan['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                self::show_plan($plan['report']);
                WP_CLI::success('Dry-run complete. No site, users, tables or uploads were created.');
            }
        } else {
            WP_CLI::success('All done, your new site is available at ' . $address['url']);
        }
    }

    private static function show_plan(array $plan): void
    {
        WP_CLI::log('Source: ' . $plan['source']);
        WP_CLI::log('Destination: ' . $plan['destination'] . ' (new site only; no overwrite)');
        WP_CLI::log('Estimated site ID: ' . $plan['destination_details']['estimated_site_id']);
        foreach ($plan['tables'] as $from => $to) {
            WP_CLI::log('Table: ' . $from . ' -> ' . $to);
        }
        foreach ($plan['users'] as $user) {
            WP_CLI::log('User: ' . $user['login'] . ' -> ' . $user['action'] . ' (' . $user['role'] . ')');
        }
        WP_CLI::log('Media: ' . $plan['uploads']['files'] . ' files, ' . $plan['uploads']['bytes'] . ' bytes');
        WP_CLI::log('Upload destination: ' . $plan['destination_details']['uploads_directory']);
        foreach ($plan['limitations'] as $limitation) {
            WP_CLI::log('Note: ' . $limitation);
        }
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

    private function import_tables(string $filename, array $sourceTables, array $targetTables): void
    {
        global $wpdb;
        $mapping = array_combine($sourceTables, $targetTables);
        $sql = Sql::map(file_get_contents($filename), $mapping);
        Files::write($filename, $sql);
        Utils::checked_command('db import', [$filename]);
    }

    private function replace_urls(array $targetTables, array $meta, array $source, array $target, int $blogId): void
    {
        Utils::checked_command('search-replace', [Utils::parse_url_for_search_replace($source['url']), Utils::parse_url_for_search_replace($target['url']), ...$targetTables], ['precise' => true], ['url' => $target['url']]);
        $from = 'wp-content/uploads' . ($meta['blog_id'] > 1 ? '/sites/' . $meta['blog_id'] : '');
        $to = 'wp-content/uploads/sites/' . $blogId;
        Utils::checked_command('search-replace', [$from, $to, ...$targetTables], ['precise' => true], ['url' => $target['url']]);
    }

    private function configure_site(array $meta, array $target, int $blogId): void
    {
        global $wpdb;
        switch_to_blog($blogId);
        try {
            if ($wpdb->update($wpdb->options, ['option_name' => $wpdb->prefix . 'user_roles'], ['option_name' => $meta['db_prefix'] . 'user_roles']) === false) {
                throw new RuntimeException('Could not map the new site role option.');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            foreach (['upload_path', 'upload_url_path'] as $option) {
                update_option($option, '');
                if (get_option($option) !== '') {
                    throw new RuntimeException('Could not reset the source upload path for the new site.');
                }
            }
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

    private function import_users(array $plan, int $blogId, Execution $execution): array
    {
        // Re-check the whole identity plan immediately before writing any users.
        $plan = Users::plan($plan);
        $ids = [];
        switch_to_blog($blogId);
        try {
            wp_roles()->for_site($blogId);
            foreach ($plan as $row) {
                $execution->assertOwned();
                $execution->run->userIntent((int) $row['ID']);
                $id = $row['target_id'];
                $created = $id === null;
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
                $execution->run->user((int) $row['ID'], (int) $id, $created);
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

    private function move_uploads(string $source, int $blogId, string $expectedPath, Execution $execution): void
    {
        switch_to_blog($blogId);
        try {
            $uploads = wp_upload_dir(null, false, true);
            if ($uploads['basedir'] !== $expectedPath || Destination::uploadPath($blogId) !== $expectedPath) {
                throw new RuntimeException('The destination upload path changed after preflight.');
            }
            if ($uploads['error']) {
                throw new RuntimeException('Cannot prepare the new site upload directory.');
            }
            if (!is_dir($expectedPath) && !mkdir($expectedPath, 0755, true)) {
                throw new RuntimeException('Cannot create the new site upload directory.');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $execution->assertOwned();
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
                    if (!chmod($destination, 0644)) {
                        throw new RuntimeException('Cannot set permissions on an imported upload.');
                    }
                    $execution->run->upload();
                }
            }
        } finally {
            restore_current_blog();
        }
    }
}
