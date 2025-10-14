<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI;
use PhpZip\ZipFile;
use PhpZip\Exception\ZipException;

/**
 * Class Utils
 * Utility functions for the RRZE-CLI plugin.
 *
 * @package RRZE\CLI
 */
class Utils
{
    /**
     * Check whether a file is a ZIP by inspecting the signature bytes.
     * Recognizes: 50 4B 03 04 (LFH), 50 4B 05 06 (EOCD empty), 50 4B 07 08 (spanned).
     *
     * @param  string $filename
     * @return bool
     */
    public static function is_zip_file(string $filename): bool
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return false;
        }
        $fh = @fopen($filename, 'rb');
        if (!$fh) {
            return false;
        }
        $sig = fread($fh, 4);
        fclose($fh);

        if ($sig === false || strlen($sig) < 4) {
            return false;
        }

        return in_array($sig, ["\x50\x4B\x03\x04", "\x50\x4B\x05\x06", "\x50\x4B\x07\x08"], true);
    }

    /**
     * Parse a URL for use in search-replace by removing its scheme
     * and returning "host[:port]/path" (normalized).
     *
     * @param  string $url
     * @return string
     */
    public static function parse_url_for_search_replace(string $url): string
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!$parts || !isset($parts['host'])) {
            return '';
        }

        $host = strtolower($parts['host']);
        if (isset($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }

        $path = $parts['path'] ?? '';
        // Normalize multiple slashes and trim trailing slash (except root).
        $path = preg_replace('#//+#', '/', $path);
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        return $host . $path;
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param  string $dirPath
     * @param  bool   $deleteParent Remove the top-level directory at the end.
     * @return void
     *
     * @throws \RuntimeException on failure to delete entries.
     */
    public static function delete_folder(string $dirPath, bool $deleteParent = true): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator(
            $dirPath,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        /** @var \SplFileInfo $path */
        foreach ($ri as $path) {
            $full = $path->getPathname();
            if ($path->isLink() || $path->isFile()) {
                if (!@unlink($full) && file_exists($full)) {
                    throw new \RuntimeException("Unable to delete file: {$full}");
                }
            } elseif ($path->isDir()) {
                if (!@rmdir($full) && is_dir($full)) {
                    throw new \RuntimeException("Unable to delete directory: {$full}");
                }
            }
        }

        if ($deleteParent) {
            @rmdir($dirPath);
        }
    }

    /**
     * Recursively move a directory and its files to a destination.
     * Falls back to copy+unlink if rename() crosses devices.
     *
     * @param  string $source
     * @param  string $dest
     * @return void
     *
     * @throws \InvalidArgumentException|\RuntimeException on failure.
     */
    public static function move_folder(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            throw new \InvalidArgumentException("Source is not a directory: {$source}");
        }
        if (!file_exists($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new \RuntimeException("Unable to create destination: {$dest}");
        }

        $it = new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($ri as $item) {
            /** @var \SplFileInfo $item */
            $rel      = $ri->getSubPathName();
            $target   = $dest . DIRECTORY_SEPARATOR . $rel;
            $itemPath = $item->getPathname();

            if ($item->isDir()) {
                if (!file_exists($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Unable to create directory: {$target}");
                }
            } else {
                $targetDir = dirname($target);
                if (!file_exists($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                    throw new \RuntimeException("Unable to create directory: {$targetDir}");
                }
                if (!@rename($itemPath, $target)) {
                    // Cross-device or permission issue: copy + unlink
                    if (!@copy($itemPath, $target) || !@unlink($itemPath)) {
                        throw new \RuntimeException("Unable to move file: {$itemPath} -> {$target}");
                    }
                }
            }
        }

        // Remove now-empty source tree
        self::delete_folder($source, true);
    }

    /**
     * Retrieve the DB prefix for a given blog ID (multisite-aware).
     *
     * @param  int $blog_id
     * @return string
     */
    public static function get_db_prefix(int $blog_id): string
    {
        global $wpdb;

        if (method_exists($wpdb, 'get_blog_prefix')) {
            return $wpdb->get_blog_prefix($blog_id);
        }

        return ($blog_id > 1) ? $wpdb->base_prefix . $blog_id . '_' : $wpdb->prefix;
    }

    /**
     * Assign a role to a user on a target blog without switch_to_blog().
     * Writes directly to user meta for that site's capabilities.
     *
     * @param  int    $blog_id
     * @param  int    $user_id
     * @param  string $role
     * @return bool|\WP_Error  True on success or WP_Error on failure.
     */
    public static function light_add_user_to_blog(int $blog_id, int $user_id, string $role)
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return new \WP_Error('user_does_not_exist', __('The requested user does not exist.'));
        }

        $details = get_blog_details($blog_id);
        if (!$details) {
            return new \WP_Error('blog_does_not_exist', __('The requested site does not exist.'));
        }

        $wp_role = get_role($role);
        if (!$wp_role) {
            return new \WP_Error('invalid_role', sprintf(__('Invalid role: %s'), $role));
        }

        $prefix   = self::get_db_prefix($blog_id);
        $caps_key = $prefix . 'capabilities';
        $level_key = $prefix . 'user_level';

        // Assign capabilities
        $caps = [$role => true];
        update_user_meta($user_id, $caps_key, $caps);

        // user_level is legacy; derive the highest level from role caps if present
        $user_level = 0;
        foreach ($wp_role->capabilities as $cap => $grant) {
            if ($grant && preg_match('/^level_(\d+)$/', $cap, $m)) {
                $user_level = max($user_level, (int) $m[1]);
            }
        }
        update_user_meta($user_id, $level_key, $user_level);

        if (!get_user_meta($user_id, 'primary_blog', true)) {
            update_user_meta($user_id, 'primary_blog', $blog_id);
            update_user_meta($user_id, 'source_domain', $details->domain);
        }

        /**
         * Fires immediately after a user is added to a site.
         *
         * @param int    $user_id User ID.
         * @param string $role    User role.
         * @param int    $blog_id Blog ID.
         */
        do_action('add_user_to_blog', $user_id, $role, $blog_id);

        wp_cache_delete($user_id, 'users');
        wp_cache_delete($blog_id . '_user_count', 'blog-details');

        return true;
    }

    /**
     * Free up object cache memory and other globals that can grow in long-running CLI processes.
     * NOTE: $wpdb->queries is only populated with SAVEQUERIES; resetting it is harmless otherwise.
     *
     * @return void
     */
    public static function delete_object_cache(): void
    {
        global $wpdb, $wp_actions, $wp_filter, $wp_object_cache;

        // Reset query log
        if (isset($wpdb->queries)) {
            $wpdb->queries = [];
        }

        // Prevent wp_actions from growing without bound
        $wp_actions = [];

        if (is_object($wp_object_cache)) {
            $wp_object_cache->group_ops      = [];
            $wp_object_cache->stats          = [];
            $wp_object_cache->memcache_debug = [];
            $wp_object_cache->cache          = [];

            if (method_exists($wp_object_cache, '__remoteset')) {
                $wp_object_cache->__remoteset();
            }
        }

        // Detach callbacks added by WP_Query when update_post_term/meta_cache are true
        if (isset($wp_filter['get_term_metadata'])) {
            if (class_exists('WP_Hook') && $wp_filter['get_term_metadata'] instanceof \WP_Hook) {
                $filter_callbacks = &$wp_filter['get_term_metadata']->callbacks;
            } else {
                $filter_callbacks = &$wp_filter['get_term_metadata'];
            }

            if (isset($filter_callbacks[10])) {
                foreach ($filter_callbacks[10] as $hook => $content) {
                    if (preg_match('#^[0-9a-f]{32}lazyload_term_meta$#', (string) $hook)) {
                        unset($filter_callbacks[10][$hook]);
                    }
                }
            }
        }
    }

    /**
     * Prepend START TRANSACTION and append COMMIT to an existing SQL dump.
     * Uses streaming to avoid loading the entire file into memory.
     *
     * @param  string $orig_filename SQL dump file path.
     * @return void
     *
     * @throws \RuntimeException on I/O failure.
     */
    public static function addTransaction(string $orig_filename): void
    {
        $in = @fopen($orig_filename, 'rb');
        if (!$in) {
            throw new \RuntimeException("Unable to open SQL file: {$orig_filename}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'php_prepend_');
        $out = @fopen($tmp, 'wb');
        if (!$out) {
            fclose($in);
            throw new \RuntimeException("Unable to open temp file: {$tmp}");
        }

        fwrite($out, 'START TRANSACTION;' . PHP_EOL);
        stream_copy_to_stream($in, $out);
        fwrite($out, PHP_EOL . 'COMMIT;' . PHP_EOL);

        fclose($in);
        fclose($out);

        if (!@unlink($orig_filename) || !@rename($tmp, $orig_filename)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to replace original SQL file: {$orig_filename}");
        }
    }

    /**
     * Switch to a blog if multisite is enabled.
     *
     * @param  int $blog_id
     * @return void
     */
    public static function maybe_switch_to_blog(int $blog_id): void
    {
        if (is_multisite()) {
            switch_to_blog($blog_id);
        }
    }

    /**
     * Restore the current blog if multisite is enabled.
     *
     * @return void
     */
    public static function maybe_restore_current_blog(): void
    {
        if (is_multisite()) {
            restore_current_blog();
        }
    }

    /**
     * Extract a ZIP archive to a destination directory.
     *
     * @param  string $filename
     * @param  string $dest_dir
     * @return void
     *
     * @throws \RuntimeException on destination creation failure.
     */
    public static function extract(string $filename, string $dest_dir): void
    {
        if (!file_exists($dest_dir) && !mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
            throw new \RuntimeException("Unable to create destination: {$dest_dir}");
        }

        $zipFile = new ZipFile();
        try {
            $zipFile->openFile($filename)
                ->extractTo($dest_dir)
                ->close();
        } catch (ZipException $e) {
            WP_CLI::warning(sprintf('Zip extract failed: %s', $e->getMessage()));
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Create a ZIP archive with provided files/directories.
     * Array keys are archive paths, values are source filesystem paths.
     *
     * @param  string $zip_file
     * @param  array  $files_to_zip [archivePath => sourcePath]
     * @return void
     */
    public static function zip(string $zip_file, array $files_to_zip): void
    {
        $zipFile = new ZipFile();
        try {
            foreach ($files_to_zip as $archivePath => $src) {
                if (!file_exists($src)) {
                    WP_CLI::warning("Path not found, skipping: {$src}");
                    continue;
                }
                if (is_dir($src)) {
                    $zipFile->addDirRecursive($src, $archivePath);
                } else {
                    if (!is_readable($src)) {
                        WP_CLI::warning("Unreadable file, skipping: {$src}");
                        continue;
                    }
                    $zipFile->addFile($src, $archivePath);
                }
            }
            $zipFile->saveAsFile($zip_file)->close();
        } catch (ZipException $e) {
            WP_CLI::warning($e->getMessage());
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Run a WP-CLI subcommand and return its full result.
     * This keeps execution within the same PHP process (no external proc).
     *
     * - Positional args with spaces are quoted.
     * - Assoc args support booleans (--flag), arrays (--key=value repeated), and spaces.
     *
     * @param  string $command      The command to run (e.g., 'db tables').
     * @param  array  $args         Positional arguments.
     * @param  array  $assoc_args   Associative arguments.
     * @param  array  $global_args  Global arguments merged into assoc args (e.g., ['url' => '...']).
     * @return mixed                Typically \WP_CLI\ProcessRun when 'return' => 'all'.
     */
    public static function runcommand(string $command, array $args = [], array $assoc_args = [], array $global_args = [])
    {
        $assoc_args = array_merge($assoc_args, $global_args);

        // Build positional args with minimal quoting for spaces.
        $positional = array_map(
            static function ($a): string {
                $a = (string) $a;
                return preg_match('/\s/', $a)
                    ? '"' . str_replace('"', '\"', $a) . '"'
                    : $a;
            },
            $args
        );

        // Build associative args (bools, arrays, scalars).
        $kv = [];
        foreach ($assoc_args as $k => $v) {
            if (is_bool($v)) {
                if ($v) {
                    $kv[] = '--' . $k;
                }
                continue;
            }
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $vv = (string) $vv;
                    $kv[] = '--' . $k . '=' . (preg_match('/\s/', $vv) ? '"' . str_replace('"', '\"', $vv) . '"' : $vv);
                }
                continue;
            }
            $v = (string) $v;
            $kv[] = '--' . $k . '=' . (preg_match('/\s/', $v) ? '"' . str_replace('"', '\"', $v) . '"' : $v);
        }

        $params  = trim(implode(' ', array_filter([$command, implode(' ', $positional), implode(' ', $kv)])));
        $options = [
            'return'     => 'all',  // Return full result object
            'launch'     => false,  // Reuse current PHP process
            'exit_error' => false,  // Don't throw on command error
        ];

        return WP_CLI::runcommand($params, $options);
    }

    /**
     * Check whether WooCommerce is active on this site or network-activated.
     *
     * @return bool
     */
    public static function is_woocommerce_active(): bool
    {
        // Site-activated
        $site_active = in_array(
            'woocommerce/woocommerce.php',
            (array) apply_filters('active_plugins', get_option('active_plugins', [])),
            true
        );

        if ($site_active) {
            return true;
        }

        // Network-activated
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network['woocommerce/woocommerce.php'])) {
                return true;
            }
        }

        return false;
    }
}
