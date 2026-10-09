<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use RuntimeException;
use WP_CLI;

/** Imports a controlled export into a newly created multisite site. */
class Import extends Command
{
    /** The wizard supplies a reviewer; direct CLI calls retain their noninteractive behavior. */
    public function __construct(private readonly ?\Closure $review = null)
    {
    }

    /**
     * Imports a package into a new site. Existing sites must be deleted manually first.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : ZIP in private storage; relative to --run-dir / RRZE_MIGRATION_RUN_DIR, or an absolute path outside web roots.
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
        if ($this->review === null) {
            Console::configure(false, isset($assoc_args['verbose']));
        }
        try {
            if (isset($assoc_args['mysql-single-transaction'])) {
                throw new RuntimeException('--mysql-single-transaction cannot make a migration with DDL atomic and is no longer supported.');
            }
            if (!empty($assoc_args['new_url'])) {
                Destination::available(SiteAddress::parse($assoc_args['new_url']));
            }
            $filename = PackageStorage::input($args[0] ?? '', $assoc_args);
            if (!$dryRun) {
                $root = PackageStorage::root($assoc_args, true);
                $run = Run::create($root);
                Diagnostics::destination($run->directory);
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
                $run->plan($plan, Media::installation());
                $reviewed = $plan['report'];
                if ($this->review !== null && !($this->review)($reviewed)) {
                    throw new RuntimeException('Import cancelled before site creation. No destination changes were made.');
                }
                $run->done();
                $run->begin('acquire_lock');
                $execution = new Execution($run);
                $run->done();
                $plan = $execution->step('recheck', static fn () => Preflight::build($package, $workspace, $assoc_args));
                if ($this->review !== null) {
                    Plan::assertUnchanged($reviewed, $plan['report']);
                }
                $run->plan($plan, Media::installation());
                $baseline = Verification::users($plan['users']);
                $run->baseline($baseline);
                $execution->step('create_site', function () use (&$blogId, $plan, $address, $run): void {
                    $expectedId = $plan['destination']['estimated_site_id'];
                    $guard = static function ($site) use (&$blogId, $expectedId, $run, $address, $plan): void {
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
                $execution->step('configure_site', fn () => $this->configure_site($plan['meta'], $address, $blogId), $owned);
                $layout = $execution->step('prepare_media', function () use ($address, $blogId, $plan, $run): array {
                    $layout = MediaTransfer::layout($address['url'], $blogId);
                    MediaTransfer::prepare($plan['media'], $layout, $run);
                    return $layout;
                }, $owned);
                $execution->step('replace_urls', function () use ($plan, $layout, $address, $blogId): void {
                    MediaTransfer::replaceUrls(array_values($plan['mapping']), $plan['media'], $layout, $plan['source'], $address);
                    // The destination may itself begin with the source URL (same-network imports).
                    // Restore these two authoritative options after the general replacement.
                    $this->set_site_urls($address, $blogId);
                }, $owned);
                $ids = $execution->step('import_users', fn () => $this->import_users($plan['users'], $blogId, $execution), $owned);
                $execution->step('remap_references', fn () => Posts::remap($blogId, $ids, $plan['fields']), $owned);
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
                    $run->finish($execution?->cancelled() ? 'interrupted' : ($error === null ? 'media_pending' : 'failed'));
                } catch (\Throwable $failure) {
                    $error = ($error ? $error . ' ' : '') . 'Could not persist the final migration status.';
                }
            }
        }
        if ($error !== null) {
            Console::get()->failure($error, $blogId, $run?->id);
        }
        if ($dryRun) {
            if ($format === 'json') {
                WP_CLI::line(json_encode($plan['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                self::show_plan($plan['report']);
                WP_CLI::success('Dry-run complete. No site, users, tables or uploads were created.');
            }
        } else {
            WP_CLI::success('Site data imported at ' . $address['url'] . ' Media transfer and verification are pending.');
            Media::instructions($plan['media'], $layout, $run->directory, $run->id);
        }
    }

    private static function show_plan(array $plan): void
    {
        Console::get()->plan($plan);
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

    private function configure_site(array $meta, array $target, int $blogId): void
    {
        global $wpdb;
        switch_to_blog($blogId);
        try {
            $this->map_roles($meta['db_prefix'], $blogId);
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete($meta['db_prefix'] . 'user_roles', 'options');
            wp_cache_delete($wpdb->prefix . 'user_roles', 'options');
            wp_roles()->for_site($blogId);
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

    private function set_site_urls(array $target, int $blogId): void
    {
        global $wpdb;
        switch_to_blog($blogId);
        try {
            foreach (['alloptions', 'notoptions', 'home', 'siteurl'] as $key) {
                wp_cache_delete($key, 'options');
            }
            foreach (['home', 'siteurl'] as $option) {
                update_option($option, untrailingslashit($target['url']));
                $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
                if ($wpdb->last_error || $value !== untrailingslashit($target['url'])) {
                    throw new RuntimeException('Could not preserve the destination URL after media mapping.');
                }
            }
        } finally {
            restore_current_blog();
        }
    }

    /** Called only within the ownership-guarded configure_site step of a newly created site. */
    private function map_roles(string $sourcePrefix, int $blogId): void
    {
        global $wpdb;
        $targetPrefix = $wpdb->get_blog_prefix($blogId);
        $table = $targetPrefix . 'options';
        $sourceKey = $sourcePrefix . 'user_roles';
        $targetKey = $targetPrefix . 'user_roles';
        // Read the imported option directly, without stale caches or option filters.
        $source = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM `$table` WHERE option_name = %s", $sourceKey), ARRAY_A);
        if ($wpdb->last_error || $source === null) {
            throw new RuntimeException('The imported source role option is missing or could not be read.');
        }
        $roles = @unserialize($source['option_value'], ['allowed_classes' => false]);
        if (!is_array($roles) || !$roles) {
            throw new RuntimeException('The imported source role option must contain a nonempty serialized role array.');
        }
        if ($sourceKey === $targetKey) {
            return;
        }
        // Plugins booted by search-replace may already have created the target key. The
        // imported definitions are authoritative for this new site; avoid a duplicate-key rename.
        $copied = $wpdb->query($wpdb->prepare(
            "INSERT INTO `$table` (option_name, option_value, autoload)
             SELECT %s, option_value, autoload FROM `$table` WHERE option_name = %s
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
            $targetKey, $sourceKey
        ));
        if ($copied === false) {
            throw new RuntimeException('Could not copy the imported role option to the new site key.');
        }
        $target = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM `$table` WHERE option_name = %s", $targetKey), ARRAY_A);
        if ($wpdb->last_error || $target !== $source) {
            throw new RuntimeException('The new site role option does not match the imported definitions.');
        }
        if ($wpdb->delete($table, ['option_name' => $sourceKey]) === false) {
            throw new RuntimeException('Could not remove the old role option key from the new site.');
        }
    }

    private function import_users(array $plan, int $blogId, Execution $execution): array
    {
        global $wpdb;
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
                    $data = array_intersect_key($row, array_flip(array_diff(Users::HEADERS, ['ID', 'site_member'])));
                    $data['user_pass'] = wp_generate_password(64, true, true);
                    $id = wp_insert_user($data);
                    if (is_wp_error($id)) {
                        throw new RuntimeException('Could not create a WordPress user for an SSO identity.');
                    }
                } elseif (Users::isMember($row)) {
                    // Unlike add_user_to_blog(), this does not change primary_blog/source_domain.
                    $user = new \WP_User($id, '', $blogId);
                    $user->set_role($row['role']);
                }
                $execution->run->user((int) $row['ID'], (int) $id, $created);
                if ($created && !Users::isMember($row)) {
                    // wp_insert_user(role: '') still writes empty membership keys. Only remove
                    // those keys for this newly created account and the owned destination site.
                    delete_user_meta($id, $wpdb->prefix . 'capabilities');
                    delete_user_meta($id, $wpdb->prefix . 'user_level');
                }
                $user = new \WP_User($id, '', $blogId);
                if (Users::isMember($row) ? !in_array($row['role'], $user->roles, true)
                    : metadata_exists('user', $id, $wpdb->prefix . 'capabilities') || metadata_exists('user', $id, $wpdb->prefix . 'user_level')) {
                    throw new RuntimeException('Could not preserve the user membership on the new site.');
                }
                $ids[(int) $row['ID']] = (int) $id;
            }
        } finally {
            restore_current_blog();
        }
        return $ids;
    }

}
