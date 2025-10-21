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
     * Check whether a file is a ZIP archive by reading its magic numbers.
     * 
     * @param string $filename Path to the file to check.
     * @return bool True if the file is a ZIP archive, false otherwise.
     */
    public static function is_zip_file(string $filename): bool
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return false;
        }
        clearstatcache(true, $filename);
        $fh = @fopen($filename, 'rb');
        if (!$fh) {
            return false;
        }
        $sig = @fread($fh, 4);
        @fclose($fh);
        if ($sig === false || strlen($sig) < 4) {
            return false;
        }
        // Magic numbers: LFH, EOCD (empty), spanning marker.
        return in_array($sig, ["\x50\x4B\x03\x04", "\x50\x4B\x05\x06", "\x50\x4B\x07\x08"], true);
    }

    /**
     * Parse a URL into a normalized host+path string for search-replace operations.
     * Omits scheme, query, fragment; normalizes case and slashes.
     * Returns empty string on parse failure.
     * 
     * @param string $url The URL to parse.
     * @return string Normalized host+path string, or empty string on failure.
     */
    public static function parse_url_for_search_replace(string $url): string
    {
        // Accept bare hosts like "example.com/foo" by prefixing a dummy scheme.
        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $url = 'http://' . ltrim($url, '/');
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $host = strtolower($parts['host']);
        // IPv6 host normalization with port -> wrap in [].
        $is_ipv6 = strpos($host, ':') !== false;
        if (isset($parts['port'])) {
            $host = $is_ipv6 ? "[{$host}]" : $host;
            $host .= ':' . (int) $parts['port'];
        }

        $path = $parts['path'] ?? '';
        $path = preg_replace('#//+#', '/', $path);
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        return $host . $path;
    }

    /**
     * Recursively delete a folder and its contents.
     * 
     * @param string $dirPath Path to the directory to delete.
     * @param bool $deleteParent Whether to delete the parent directory itself. Defaults to true.
     * @throws \RuntimeException If unable to delete files or directories.
     * @return void
     */
    public static function delete_folder(string $dirPath, bool $deleteParent = true): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator(
            $dirPath,
            \FilesystemIterator::SKIP_DOTS
            // We *do not* follow symlinks to avoid accidental traversal.
        );
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        /** @var \SplFileInfo $path */
        foreach ($ri as $path) {
            $full = $path->getPathname();
            if ($path->isLink() || $path->isFile()) {
                if (!@unlink($full) && file_exists($full)) {
                    // Try to relax permissions and retry once.
                    @chmod($full, 0644);
                    if (!@unlink($full) && file_exists($full)) {
                        throw new \RuntimeException("Unable to delete file: {$full}");
                    }
                }
            } elseif ($path->isDir()) {
                if (!@rmdir($full) && is_dir($full)) {
                    @chmod($full, 0755);
                    if (!@rmdir($full) && is_dir($full)) {
                        throw new \RuntimeException("Unable to delete directory: {$full}");
                    }
                }
            }
        }

        if ($deleteParent) {
            @rmdir($dirPath);
        }
    }

    /**
     * Recursively move a folder and its contents to a new location.
     * 
     * @param string $source Path to the source directory.
     * @param string $dest Path to the destination directory.
     * @throws \InvalidArgumentException If the source is not a directory.
     * @throws \RuntimeException If unable to move files or directories.
     * @return void
     */
    public static function move_folder(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            throw new \InvalidArgumentException("Source is not a directory: {$source}");
        }

        // Prevent moving into its own subtree.
        $srcReal  = rtrim(realpath($source) ?: $source, DIRECTORY_SEPARATOR);
        $destReal = rtrim($dest, DIRECTORY_SEPARATOR);
        if (strpos($destReal, $srcReal . DIRECTORY_SEPARATOR) === 0) {
            throw new \RuntimeException("Destination cannot be inside source ({$dest}).");
        }

        // Fast path: try a simple rename first (same filesystem).
        if (@rename($source, $dest)) {
            return;
        }

        if (!file_exists($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new \RuntimeException("Unable to create destination: {$dest}");
        }

        $it = new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($ri as $item) {
            /** @var \SplFileInfo $item */
            $rel      = $it->getSubPathname();
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
                    // Cross-device or permission issue: copy + unlink, preserve perms & mtime.
                    if (!@copy($itemPath, $target)) {
                        throw new \RuntimeException("Unable to copy file: {$itemPath} -> {$target}");
                    }
                    @chmod($target, @fileperms($itemPath) & 0777);
                    @touch($target, @filemtime($itemPath) ?: time());
                    if (!@unlink($itemPath)) {
                        throw new \RuntimeException("Unable to unlink source: {$itemPath}");
                    }
                }
            }
        }

        self::delete_folder($source, true);
    }

    /**
     * Get the database table prefix for a given blog ID in multisite.
     * 
     * @param int $blog_id The blog ID.
     * @return string The database table prefix for the specified blog.
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
     * Assign a role to a user on a target blog by writing capabilities meta.
     * Merges with existing caps to avoid nuking custom capabilities.
     * 
     * @param int $blog_id The blog ID.
     * @param int $user_id The user ID.
     * @param string $role The role to assign.
     * @return true|\WP_Error True on success, WP_Error on failure.
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

        $prefix     = self::get_db_prefix($blog_id);
        $caps_key   = $prefix . 'capabilities';
        $level_key  = $prefix . 'user_level';

        // Merge with existing capabilities for that blog.
        $existing_caps = get_user_meta($user_id, $caps_key, true);
        if (!is_array($existing_caps)) {
            $existing_caps = [];
        }
        // Clear other roles (WordPress expects one role per blog), keep non-role caps.
        foreach (array_keys($existing_caps) as $k) {
            if (strpos($k, 'level_') === 0) {
                unset($existing_caps[$k]);
            }
        }
        $caps = array_merge($existing_caps, [$role => true]);
        update_user_meta($user_id, $caps_key, $caps);

        // Derive user_level (legacy) from role.
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

        do_action('add_user_to_blog', $user_id, $role, $blog_id);

        // Better than wp_cache_delete on raw groups.
        if (function_exists('clean_user_cache')) {
            clean_user_cache($user_id);
        } else {
            wp_cache_delete($user_id, 'users');
        }
        wp_cache_delete($blog_id . '_user_count', 'blog-details');

        return true;
    }

    /**
     * Clear WordPress object cache, query log, and lazy term meta callbacks.
     * Useful between operations in long-running WP-CLI processes.
     * 
     * @return void
     */
    public static function delete_object_cache(): void
    {
        global $wpdb, $wp_actions, $wp_filter, $wp_object_cache;

        if (isset($wpdb->queries)) {
            $wpdb->queries = [];
        }

        // Keep a bounded history of actions instead of wiping completely.
        if (is_array($wp_actions) && count($wp_actions) > 200) {
            $wp_actions = array_slice($wp_actions, -100, null, true);
        }

        if (is_object($wp_object_cache)) {
            // Prefer runtime-flush if available (doesn't touch persistent backends cluster-wide).
            if (method_exists($wp_object_cache, 'flush_runtime')) {
                $wp_object_cache->flush_runtime();
            } else {
                $wp_object_cache->group_ops      = [];
                $wp_object_cache->stats          = [];
                $wp_object_cache->memcache_debug = [];
                $wp_object_cache->cache          = [];
                if (method_exists($wp_object_cache, '__remoteset')) {
                    $wp_object_cache->__remoteset();
                }
            }
        }

        // Detach lazy term meta callbacks added dynamically.
        if (isset($wp_filter['get_term_metadata'])) {
            $hookObj = $wp_filter['get_term_metadata'];
            $callbacks = (class_exists('WP_Hook') && $hookObj instanceof \WP_Hook)
                ? $hookObj->callbacks
                : $hookObj;

            if (isset($callbacks[10])) {
                foreach (array_keys($callbacks[10]) as $hook) {
                    if (preg_match('#^[0-9a-f]{32}lazyload_term_meta$#', (string) $hook)) {
                        unset($callbacks[10][$hook]);
                    }
                }
            }

            if (class_exists('WP_Hook') && $hookObj instanceof \WP_Hook) {
                $wp_filter['get_term_metadata']->callbacks = $callbacks;
            } else {
                $wp_filter['get_term_metadata'] = $callbacks;
            }
        }
    }

    /**
     * Wrap SQL dump file contents in a transaction (START TRANSACTION; ... COMMIT;).
     * Skips gzipped files and files already containing a transaction.
     * 
     * @param string $orig_filename Path to the original SQL dump file.
     * @throws \RuntimeException If unable to read/write files.
     * @return void
     */
    public static function addTransaction(string $orig_filename): void
    {
        // Quick guards: ignore gzipped dumps.
        if (preg_match('/\.(sql\.gz|gz)$/i', $orig_filename)) {
            WP_CLI::warning('addTransaction skipped: gzip-compressed dump.');
            return;
        }

        $in = @fopen($orig_filename, 'rb');
        if (!$in) {
            throw new \RuntimeException("Unable to open SQL file: {$orig_filename}");
        }

        // Avoid double-wrapping: peek first KB for START TRANSACTION;
        $head = @fread($in, 1024) ?: '';
        if (stripos($head, 'START TRANSACTION') !== false) {
            fclose($in);
            return;
        }
        // Rewind after peek.
        rewind($in);

        $tmp = tempnam(sys_get_temp_dir(), 'php_prepend_');
        $out = @fopen($tmp, 'wb');
        if (!$out) {
            fclose($in);
            throw new \RuntimeException("Unable to open temp file: {$tmp}");
        }

        fwrite($out, "START TRANSACTION;\n");
        stream_copy_to_stream($in, $out);
        fwrite($out, "\nCOMMIT;\n");

        fclose($in);
        fclose($out);

        if (!@unlink($orig_filename) || !@rename($tmp, $orig_filename)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to replace original SQL file: {$orig_filename}");
        }
    }

    /**
     * Switch to a given blog in multisite if not already on it.
     * 
     * @param int $blog_id The blog ID to switch to.
     * @return void
     */
    public static function maybe_switch_to_blog(int $blog_id): void
    {
        if (is_multisite() && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
        }
    }

    /**
     * Restore to the previous blog in multisite if applicable.
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
     * Extract a ZIP archive to a destination directory with zip-slip protection.
     * 
     * @param string $filename Path to the ZIP archive.
     * @param string $dest_dir Path to the destination directory.
     * @throws \RuntimeException If unable to create destination directory.
     * @return void
     */
    public static function extract(string $filename, string $dest_dir): void
    {
        if (!file_exists($dest_dir) && !mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
            throw new \RuntimeException("Unable to create destination: {$dest_dir}");
        }

        $zipFile = new ZipFile();
        try {
            $zipFile->openFile($filename);

            // Zip-slip guard: inspect entry names before extraction.
            foreach ($zipFile->getListFiles() as $entry) {
                // Reject absolute paths or parent directory traversal.
                if (strpos($entry, "\0") !== false || str_starts_with($entry, '/') || str_contains($entry, '..' . '/')) {
                    throw new ZipException("Unsafe entry path detected: {$entry}");
                }
            }

            $zipFile->extractTo($dest_dir)->close();
        } catch (ZipException $e) {
            WP_CLI::warning(sprintf('Zip extract failed: %s', $e->getMessage()));
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Create a ZIP archive with provided files/directories.
     * Array keys are archive paths (use forward slashes), values are source paths.
     * 
     * @param string $zip_file Path to the output ZIP archive.
     * @param array $files_to_zip Associative array of archive paths to source paths.
     * @throws \RuntimeException If unable to create zip directory.
     * @return void
     */
    public static function zip(string $zip_file, array $files_to_zip): void
    {
        $zipDir = dirname($zip_file);
        if (!is_dir($zipDir) && !mkdir($zipDir, 0775, true) && !is_dir($zipDir)) {
            throw new \RuntimeException("Unable to create zip directory: {$zipDir}");
        }

        $zipFile = new ZipFile();
        try {
            foreach ($files_to_zip as $archivePath => $src) {
                // Normalize archive path to forward slashes and strip leading slashes.
                $archivePath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $archivePath), '/');

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

            // Optional: set compression level if supported by backend.
            // $zipFile->setCompressionLevel(\PhpZip\Constants\ZipCompressionLevel::MAXIMUM);

            $zipFile->saveAsFile($zip_file)->close();
        } catch (ZipException $e) {
            WP_CLI::warning($e->getMessage());
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Run a WP-CLI subcommand and return its full result using the current PHP process.
     * 
     * @param string $command The WP-CLI command to run (e.g., 'plugin install').
     * @param array $args Positional arguments for the command.
     * @param array $assoc_args Associative arguments for the command.
     * @param array $global_args Global arguments for WP-CLI.
     * @return array The full result of the command execution.
     */
    public static function runcommand(string $command, array $args = [], array $assoc_args = [], array $global_args = [])
    {
        $assoc_args = array_merge($assoc_args, $global_args);

        // Positional args: quote when whitespace; escape embedded quotes.
        $positional = array_map(
            static function ($a): string {
                $a = (string) $a;
                if ($a === '') {
                    return '""';
                }
                return preg_match('/\s/', $a)
                    ? '"' . str_replace('"', '\"', $a) . '"'
                    : $a;
            },
            $args
        );

        // Assoc args: bools, arrays, scalars.
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
            'return'     => 'all',
            'launch'     => false,
            'exit_error' => false,
        ];

        return WP_CLI::runcommand($params, $options);
    }

    /**
     * Check whether WooCommerce is active on this site or network-activated.
     * 
     * @return bool True if WooCommerce is active, false otherwise.
     */
    public static function is_woocommerce_active(): bool
    {
        // Quick heuristic: class loaded.
        if (class_exists('WooCommerce')) {
            return true;
        }

        // Site-activated.
        $site_active = in_array(
            'woocommerce/woocommerce.php',
            (array) apply_filters('active_plugins', get_option('active_plugins', [])),
            true
        );
        if ($site_active) {
            return true;
        }

        // Network-activated.
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network['woocommerce/woocommerce.php'])) {
                return true;
            }
        }

        return false;
    }
}
