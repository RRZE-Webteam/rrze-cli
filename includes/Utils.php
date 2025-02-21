<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI;
use PhpZip\ZipFile;
use PhpZip\Exception\ZipException;

class Utils
{
    /**
     * Checks if $filename is a zip file by checking it's first few bytes sequence.
     *
     * @param string $filename
     * @return bool
     */
    public static function is_zip_file($filename)
    {
        $fh = fopen($filename, 'r');

        if (!$fh) {
            return false;
        }

        $blob = fgets($fh, 5);

        fclose($fh);

        if (strpos($blob, 'PK') !== false) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Parses a url for use in search-replace by removing its scheme.
     *
     * @param string $url
     * @return string
     */
    public static function parse_url_for_search_replace($url)
    {
        $parsed_url = parse_url(esc_url($url));
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';

        return $parsed_url['host'] . $path;
    }

    /**
     * Recursively removes a directory and its files.
     *
     * @param string $dirPath
     * @param bool   $deleteParent
     */
    public static function delete_folder($dirPath, $deleteParent = true)
    {
        $limit = 0;
        while (file_exists($dirPath) && $limit++ < 10) {
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                ) as $path
            ) {
                $path->isFile() ? @unlink($path->getPathname()) : @rmdir($path->getPathname());
            }

            if ($deleteParent) {
                rmdir($dirPath);
            }
        }
    }

    /**
     * Recursively copies a directory and its files.
     *
     * @param string $source
     * @param string $dest
     */
    public static function move_folder($source, $dest)
    {
        if (!file_exists($dest)) {
            mkdir($dest);
        }

        foreach (
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            ) as $item
        ) {
            if ($item->isDir()) {
                $dir = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if (!file_exists($dir)) {
                    mkdir($dir);
                }
            } else {
                $dest_file = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if (!file_exists($dest_file)) {
                    rename($item, $dest_file);
                }
            }
        }
    }

    /**
     * Retrieves the db prefix based on the $blog_id.
     *
     * @uses wpdb
     *
     * @param int $blog_id
     * @return string
     */
    public static function get_db_prefix($blog_id)
    {
        global $wpdb;

        if ($blog_id > 1) {
            $new_db_prefix = $wpdb->base_prefix . $blog_id . '_';
        } else {
            $new_db_prefix = $wpdb->prefix;
        }

        return $new_db_prefix;
    }

    /**
     * Does the same thing that add_user_to_blog does, but without calling switch_to_blog().
     *
     * @param int    $blog_id
     * @param int    $user_id
     * @param string $role
     * @return \WP_Error
     */
    public static function light_add_user_to_blog($blog_id, $user_id, $role)
    {
        $user = get_userdata($user_id);

        if (!$user) {
            restore_current_blog();
            return new \WP_Error('user_does_not_exist', __('The requested user does not exist.'));
        }

        if (!get_user_meta($user_id, 'primary_blog', true)) {
            update_user_meta($user_id, 'primary_blog', $blog_id);
            $details = get_blog_details($blog_id);
            update_user_meta($user_id, 'source_domain', $details->domain);
        }

        $user->set_role($role);

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
    }

    /**
     * Frees up object cache memory for long running processes.
     */
    public static function delete_object_cache()
    {
        global $wpdb, $wp_actions, $wp_filter, $wp_object_cache;

        // Reset queries
        $wpdb->queries = [];
        // Prevent wp_actions from growing out of control
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

        /*
         * The WP_Query class hooks a reference to one of its own methods
         * onto filters if update_post_term_cache or update_post_meta_cache are true, 
         * which prevents PHP's garbage collector from cleaning up the WP_Query 
         * instance on long-running processes.
         *
         * By manually removing these callbacks (often created by things
         * like get_posts()), we're able to properly unallocate memory
         * once occupied by a WP_Query object.
         *
         */
        if (isset($wp_filter['get_term_metadata'])) {
            /*
             * WP >= 4.7 has a new WP_Hook class.
             */
            if (class_exists('WP_Hook') && $wp_filter['get_term_metadata'] instanceof \WP_Hook) {
                $filter_callbacks = &$wp_filter['get_term_metadata']->callbacks;
            } else {
                $filter_callbacks = &$wp_filter['get_term_metadata'];
            }

            if (isset($filter_callbacks[10])) {
                foreach ($filter_callbacks[10] as $hook => $content) {
                    if (preg_match('#^[0-9a-f]{32}lazyload_term_meta$#', $hook)) {
                        unset($filter_callbacks[10][$hook]);
                    }
                }
            }
        }
    }

    /**
     * Add START TRANSACTION and COMMIT to the sql export.
     *
     * @param string $orig_filename SQL dump file name.
     */
    public static function addTransaction($orig_filename)
    {
        $context   = stream_context_create();
        $orig_file = fopen($orig_filename, 'r', 1, $context);

        $temp_filename = tempnam(sys_get_temp_dir(), 'php_prepend_');
        file_put_contents($temp_filename, 'START TRANSACTION;' . PHP_EOL);
        file_put_contents($temp_filename, $orig_file, FILE_APPEND);
        file_put_contents($temp_filename, 'COMMIT;', FILE_APPEND);

        fclose($orig_file);
        unlink($orig_filename);
        rename($temp_filename, $orig_filename);
    }

    /**
     * Switches to another blog if on Multisite
     *
     * @param $blog_id
     */
    public static function maybe_switch_to_blog($blog_id)
    {
        if (is_multisite()) {
            switch_to_blog($blog_id);
        }
    }

    /**
     * Restore the current blog if on multisite
     */
    public static function maybe_restore_current_blog()
    {
        if (is_multisite()) {
            restore_current_blog();
        }
    }


    /**
     * Extracts a zip file to the $dest_dir.
     *
     * @param string $filename
     * @param string $dest_dir
     */
    public static function extract($filename, $dest_dir)
    {
        if (!file_exists($dest_dir)) {
            mkdir($dest_dir);
        }

        $zipFile = new \PhpZip\ZipFile();
        try {
            $zipFile
                ->openFile($filename) // open archive from file
                ->extractTo($dest_dir) // extract files to the specified directory    
                ->close(); // close archive  
        } catch (ZipException $e) {
            // handle exception
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Creates a zip files with the provided files/folder to zip
     *
     * @param string $zip_files    The name of the zip file
     * @param array  $files_to_zip The files to include in the zip file
     *
     * @return void
     */
    public static function zip($zip_file, $files_to_zip)
    {
        // create new archive
        $zipFile = new ZipFile();
        try {
            foreach ($files_to_zip as $key => $file) {
                if (is_dir($file)) {
                    $zipFile->addDirRecursive($file, $key); // add a directory and all its contents
                    continue;
                }
                $zipFile->addFile($file, $key); // add an entry from the file
            }
            $zipFile
                ->saveAsFile($zip_file) // save the archive to a file
                ->close(); // close archive
        } catch (ZipException $e) {
            WP_CLI::warning($e->getMessage());
        } finally {
            $zipFile->close();
        }
    }

    /**
     * Run a command within WP_CLI
     *
     * @param string $command     The command to run
     * @param array  $args        The command arguments
     * @param array  $assoc_args  The associative arguments
     * @param array  $global_args The global arguments
     *
     * @return
     */
    public static function runcommand($command, $args = [], $assoc_args = [], $global_args = [])
    {
        $assoc_args = array_merge($assoc_args, $global_args);

        $transformed_assoc_args = [];

        foreach ($assoc_args as $key => $arg) {
            $transformed_assoc_args[] = '--' . $key . '=' . $arg;
        }
        $params = sprintf('%s %s', implode(' ', $args), implode(' ', $transformed_assoc_args));

        $options = [
            'return'     => 'all', // Returns all data
            'launch'     => false, // Do not start a new system process
            'exit_error' => false, // Prevent WP-CLI from stopping execution on error
        ];

        error_log(sprintf('%s %s', $command, $params));
        return WP_CLI::runcommand(sprintf('%s %s', $command, $params), $options);
    }

    /**
     * Checks if WooCommerce is active.
     *
     * @return bool
     */
    public static function is_woocommerce_active()
    {
        return in_array(
            'woocommerce/woocommerce.php',
            apply_filters('active_plugins', get_option('active_plugins'))
        );
    }
}
