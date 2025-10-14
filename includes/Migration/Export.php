<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Exports an entire website into a zip package.
 *
 * @package RRZE\CLI
 */
class Export extends Command
{
    /**
     * Export a website to a ZIP file.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : The name (or path) of the exported ZIP file. Relative paths are resolved from ABSPATH.
     *
     * [--tables=<table_list>]
     * : Comma-separated list of tables to be exported.
     *
     * [--custom-tables=<custom_table_list>]
     * : Comma-separated list of non-standard tables to be exported.
     *
     * [--plugins]
     * : Includes the whole plugins directory. Don't use this option if you don't know what you're doing.
     *
     * [--themes]
     * : Includes website theme/child directory. Don't use this option if you don't know what you're doing.
     *
     * [--uploads]
     * : Includes website uploads directory. Don't use this option if you don't know what you're doing.
     *
     * [--verbose]
     * : Display additional details during command execution.
     *
     * [--usersuffix=<string>]
     * : Optional. Suffix to append to the `user_login` field in the CSV export.
     *
     * [--usersuffixtrim=<string>]
     * : Optional. Suffix to trim from the `user_login` field if present (e.g. "@fau.de").
     *
     * ## EXAMPLES
     *
     * Exports a website to website.zip file.
     * wp rrze-migration export all [--url=website-url]
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function all($args = [], $assoc_args = [])
    {
        global $wpdb;

        // Ensure get_plugins() is available in WP-CLI context.
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $verbose = isset($assoc_args['verbose']);

        $blogId = get_current_blog_id();

        $site_data = [
            'url'             => esc_url(home_url()),
            'name'            => sanitize_text_field(get_bloginfo('name')),
            'admin_email'     => sanitize_text_field(get_bloginfo('admin_email')),
            'site_language'   => sanitize_text_field(get_bloginfo('language')),
            'db_prefix'       => $wpdb->prefix,
            'plugins'         => get_plugins(),
            'blog_plugins'    => get_option('active_plugins'),
            'network_plugins' => is_multisite() ? get_site_option('active_sitewide_plugins') : [],
            'blog_id'         => $blogId,
        ];

        // Parse args and defaults for this subcommand.
        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_title($site_data['name']) . '.zip',
            ],
            $args,
            [
                'tables'         => '',
                'custom-tables'  => '',
                'usersuffix'     => '',
                'usersuffixtrim' => '',
            ],
            $assoc_args
        );

        // Resolve the ZIP output path (supports absolute or relative).
        $output   = $this->args[0];
        $zip_file = (strpos($output, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\\\#', $output))
            ? $output
            : rtrim(ABSPATH, '/\\') . '/' . ltrim($output, '/\\');

        // Ensure target directory exists.
        $zip_dir = dirname($zip_file);
        if (!is_dir($zip_dir) && !mkdir($zip_dir, 0775, true) && !is_dir($zip_dir)) {
            WP_CLI::error(sprintf(__('Unable to create output directory: %s', 'rrze-cli'), $zip_dir));
        }

        $include_plugins = isset($this->assoc_args['plugins']);
        $include_themes  = isset($this->assoc_args['themes']);
        $include_uploads = isset($this->assoc_args['uploads']);

        // Forward user-related args to the users() export step.
        $users_assoc_args = [
            'usersuffix'     => $this->assoc_args['usersuffix'],
            'usersuffixtrim' => $this->assoc_args['usersuffixtrim'],
            'blog_id'        => $blogId,
        ];

        // Forward tables args to the tables() export step.
        $tables_assoc_args = [
            'tables'         => $this->assoc_args['tables'],
            'custom-tables'  => $this->assoc_args['custom-tables'],
            'blog_id'        => $blogId,
        ];

        // Use a random token to avoid collisions and for security hygiene.
        $rand           = bin2hex(random_bytes(8));
        $safe_site      = sanitize_title($site_data['name']);
        $users_file     = "rrze-migration-{$rand}-{$safe_site}.csv";
        $tables_file    = "rrze-migration-{$rand}-{$safe_site}.sql";
        $meta_data_file = "rrze-migration-{$rand}-{$safe_site}.json";

        WP_CLI::log(__('Exporting site meta data...', 'rrze-cli'));
        file_put_contents($meta_data_file, wp_json_encode($site_data));

        WP_CLI::log(__('Exporting users...', 'rrze-cli'));
        $this->users([$users_file], $users_assoc_args, $verbose);

        WP_CLI::log(__('Exporting tables...', 'rrze-cli'));
        $this->tables([$tables_file], $tables_assoc_args, $verbose);

        // Remove any previous archive with same path to avoid appending.
        if (file_exists($zip_file)) {
            unlink($zip_file);
        }

        // Map archive paths (keys) to source filesystem paths (values).
        $files_to_zip = [
            $users_file     => ABSPATH . $users_file,
            $tables_file    => ABSPATH . $tables_file,
            $meta_data_file => ABSPATH . $meta_data_file,
        ];

        if ($include_plugins) {
            WP_CLI::log(__('Including plugins directory...', 'rrze-cli'));
            $files_to_zip['wp-content/plugins'] = WP_PLUGIN_DIR;
        }

        if ($include_themes) {
            WP_CLI::log(__('Including themes directory...', 'rrze-cli'));
            $theme_dir = get_template_directory();
            $files_to_zip['wp-content/themes/' . basename($theme_dir)] = $theme_dir;

            if ($theme_dir !== get_stylesheet_directory()) {
                $child_theme_dir = get_stylesheet_directory();
                $files_to_zip['wp-content/themes/' . basename($child_theme_dir)] = $child_theme_dir;
            }
        }

        if ($include_uploads) {
            WP_CLI::log(__('Including website uploads directory...', 'rrze-cli'));
            $upload_dir = wp_upload_dir();
            $files_to_zip['wp-content/uploads'] = $upload_dir['basedir'];
        }

        WP_CLI::log(__('Zipping files...', 'rrze-cli'));
        Utils::zip($zip_file, $files_to_zip);

        // Cleanup temp files.
        foreach ([$users_file, $tables_file, $meta_data_file] as $tmp) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }

        if (file_exists($zip_file)) {
            WP_CLI::success(
                sprintf(
                    /* translators: %s: zip file name */
                    __('A zip file named %s has been created', 'rrze-cli'),
                    $zip_file
                )
            );
        } else {
            WP_CLI::warning(__('Something went wrong while trying to create the zip file', 'rrze-cli'));
        }
    }

    /**
     * Export database tables of a website.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : The name of the exported SQL file.
     *
     * [--tables=<table_list>]
     * : Comma-separated list of tables to be exported.
     *
     * [--custom-tables=<custom_table_list>]
     * : Comma-separated list of non-standard tables to be exported.
     *
     * ## EXAMPLES
     *
     *     # Exports all standard tables of a website to a sql file.
     *     $ wp rrze-migration export tables [--url=website-url]
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function tables($args = [], $assoc_args = [], $verbose = true)
    {
        global $wpdb;

        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_text_field(get_bloginfo('name')) . '.sql',
            ],
            $args,
            [
                'tables'        => '',
                'custom-tables' => '',
            ],
            $assoc_args
        );

        $filename = $this->args[0];
        if (empty($filename)) {
            WP_CLI::error(__('Please provide a filename for the exported SQL file', 'rrze-cli'));
        }

        $url = get_home_url();

        // Build list of tables to export.
        if (empty($this->assoc_args['tables']) && empty($this->assoc_args['custom-tables'])) {
            if (is_multisite()) {
                // Multisite: Export only tables with the current blog prefix (e.g., wp_3_).
                $tables_result = Utils::runcommand('db tables', [], ['format' => 'csv', 'all-tables-with-prefix' => 1], ['url' => $url]);

                if ($tables_result->return_code === 0) {
                    $all_tables = explode(',', $tables_result->stdout);
                    $prefix = $wpdb->prefix;

                    // Filter by current blog prefix.
                    $tables = array_filter($all_tables, function ($table) use ($prefix) {
                        return strpos($table, $prefix) === 0;
                    });
                } else {
                    WP_CLI::error(__('Could not retrieve the list of tables.', 'rrze-cli'));
                }
            } else {
                // Single site: Export all tables in the database (no prefix filtering).
                $tables_result = Utils::runcommand('db tables', [], ['format' => 'csv'], ['url' => $url]);

                if ($tables_result->return_code === 0) {
                    $tables = explode(',', $tables_result->stdout);
                } else {
                    WP_CLI::error(__('Could not retrieve the list of tables.', 'rrze-cli'));
                }
            }
        } else {
            // If user specified tables or custom-tables, use exactly those.
            $tables = [];
            if (!empty($this->assoc_args['tables'])) {
                $tables = explode(',', $this->assoc_args['tables']);
            }
            if (!empty($this->assoc_args['custom-tables'])) {
                $custom_tables = explode(',', $this->assoc_args['custom-tables']);
                $tables = array_merge($tables, $custom_tables);
            }
        }

        if (is_array($tables) && !empty($tables)) {
            // Pass URL for consistency with previous calls.
            $export = Utils::runcommand('db export', [$filename], ['tables' => implode(',', $tables)], ['url' => $url]);

            if (0 === $export->return_code) {
                $this->success(__('The export is now complete', 'rrze-cli'), $verbose);
            } else {
                WP_CLI::error(__('Something went wrong while trying to export the database', 'rrze-cli'));
            }
        } else {
            WP_CLI::error(__('Unable to get the list of tables to be exported', 'rrze-cli'));
        }
    }

    /**
     * Export all users to a CSV file.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : The name of the exported CSV file.
     *
     * [--woocomerce]
     * : (Deprecated/unused) Previously intended to include WC customer-only users.
     *
     * [--usersuffix=<string>]
     * : Optional. Suffix to append to the `user_login` field in the CSV export.
     *
     * [--usersuffixtrim=<string>]
     * : Optional. Suffix to trim from the `user_login` field if present (e.g. "@fau.de").
     *
     * ## EXAMPLES
     *
     *     # Exports all website users to a CSV file.
     *     $ wp rrze-migration export users [--url=website-url]
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function users($args = [], $assoc_args = [], $verbose = true)
    {
        $blogId = isset($assoc_args['blog_id']) ? (int) $assoc_args['blog_id'] : get_current_blog_id();

        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_text_field(get_bloginfo('name')) . '.csv',
            ],
            $args,
            [
                'woocomerce'     => false, // kept for backward compatibility; currently unused
                'usersuffix'     => '',
                'usersuffixtrim' => '',
            ],
            $assoc_args
        );

        // Normalize provided suffixes.
        $usersuffix     = isset($this->assoc_args['usersuffix']) ? trim($this->assoc_args['usersuffix']) : '';
        $usersuffixtrim = isset($this->assoc_args['usersuffixtrim']) ? trim($this->assoc_args['usersuffixtrim']) : '';
        $norm_add       = $this->normalize_suffix($usersuffix);
        $norm_trim      = $this->normalize_suffix($usersuffixtrim);

        if ($norm_add !== '' && $norm_trim !== '' && $norm_add === $norm_trim) {
            WP_CLI::warning(__('--usersuffix and --usersuffixtrim are identical; no suffix will be appended after trimming.', 'rrze-cli'));
        }

        $filename  = $this->args[0];
        $delimiter = ',';

        $file_handler = fopen($filename, 'w+');

        if (!$file_handler) {
            WP_CLI::error(__('Impossible to create the file', 'rrze-cli'));
        }

        $headers = self::getUserCSVHeaders();

        $users_args = [
            'fields'  => 'all',
            'blog_id' => $blogId,
        ];

        $count         = 0;
        $users         = get_users($users_args);
        $user_data_arr = [];

        // First pass: assemble each user's base data + filtered meta.
        foreach ($users as $user) {
            $role = isset($user->roles[0]) ? $user->roles[0] : '';

            // Start with raw login.
            $user_login = $user->data->user_login;

            // 1) Trim suffix if requested and present at the end.
            if ($norm_trim !== '' && $this->ends_with($user_login, $norm_trim)) {
                $user_login = substr($user_login, 0, -strlen($norm_trim));
            }

            // 2) Append suffix if requested and there is no '@' already.
            if ($norm_add !== '' && strpos($user_login, '@') === false) {
                $user_login .= $norm_add;
            }

            $user_data = [
                // General Info.
                $user->data->ID,
                $user_login,
                $user->data->user_pass,
                $user->data->user_nicename,
                $user->data->user_email,
                $user->data->user_url,
                $user->data->user_registered,
                $role,
                $user->data->user_status,
                $user->data->display_name,

                // User Meta (common keys).
                $user->get('first_name'),
                $user->get('last_name'),
                $user->get('nickname'),
                $user->get('url'),
                $user->get('description'),
                $user->get('_application_passwords'),
            ];

            // Keep array length consistent with headers (pre-fill).
            if (count($headers) - count($user_data) > 0) {
                $user_temp_data_arr = array_fill(0, count($headers) - count($user_data), '');
                $user_data = array_merge($user_data, $user_temp_data_arr);
            }

            // Map to associative array using headers as keys (order matters).
            $user_data = array_combine($headers, $user_data);

            // Load all user meta to be filtered against headers.
            $user_meta = get_user_meta($user->data->ID);
            $meta_keys = array_keys($user_meta);

            // Remove all meta keys not listed in headers (avoid leaking unrelated meta).
            foreach ($meta_keys as $user_meta_key) {
                if (!in_array($user_meta_key, $headers, true)) {
                    unset($user_meta[$user_meta_key]);
                }
            }

            // Re-add kept meta into the row.
            foreach (array_keys($user_meta) as $user_meta_key) {
                $value = $user_meta[$user_meta_key];

                // get_user_meta() returns arrays for multi values; flatten if simple.
                if (is_array($value) && count($value) === 1) {
                    $value = $value[0];
                }

                // Serialize arrays/objects to keep CSV single-cell semantics.
                if (is_array($value) || is_object($value)) {
                    $value = serialize($value);
                }

                $user_data[$user_meta_key] = $value;
            }

            /**
             * Filters the default set of user data to be exported/imported.
             *
             * @param array    $data The row array keyed by headers.
             * @param \WP_User $user The user object.
             */
            $custom_user_data = apply_filters('rrze_migration_export_user_data', [], $user);

            if (!empty($custom_user_data)) {
                $user_data = array_merge($user_data, $custom_user_data);
            }

            // Ensure every header key exists in the row (fill missing with empty string).
            foreach ($headers as $h) {
                if (!array_key_exists($h, $user_data)) {
                    $user_data[$h] = '';
                }
            }

            // Sanity check: row length must match headers length.
            if (count(array_values($user_data)) !== count($headers)) {
                WP_CLI::error(__('The headers and data length are not matching', 'rrze-cli'));
            }

            $user_data_arr[] = $user_data;
            $count++;
        }

        // Write headers first.
        fputcsv($file_handler, $headers, $delimiter);

        // Then write rows in header order.
        foreach ($user_data_arr as $user_data) {
            $ordered = [];
            foreach ($headers as $h) {
                $ordered[] = array_key_exists($h, $user_data) ? $user_data[$h] : '';
            }
            fputcsv($file_handler, $ordered, $delimiter);
        }

        fclose($file_handler);

        $this->success(
            sprintf(
                /* translators: %d = number of users exported */
                __('%d users have been exported', 'rrze-cli'),
                absint($count)
            ),
            $verbose
        );
    }

    /**
     * Returns the User Headers (first row) for the CSV export file.
     *
     * @return array
     */
    public static function getUserCSVHeaders()
    {
        $headers = [
            // General Info.
            'ID',
            'user_login',
            'user_pass',
            'user_nicename',
            'user_email',
            'user_url',
            'user_registered',
            'role',
            'user_status',
            'display_name',

            // User Meta (common subset).
            'first_name',
            'last_name',
            'nickname',
            'url',
            'description',
            '_application_passwords',
        ];

        /**
         * Filters the default set of user headers to be exported/imported.
         *
         * @param array
         */
        $custom_headers = apply_filters('rrze_migration_export_user_headers', []);

        if (!empty($custom_headers)) {
            $headers = array_merge($headers, $custom_headers);
        }

        return $headers;
    }

    /**
     * Normalize a suffix so it always starts with '@' (if not empty).
     *
     * @param string $suffix
     * @return string
     */
    private function normalize_suffix(string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return '';
        }
        return $suffix[0] === '@' ? $suffix : '@' . $suffix;
    }

    /**
     * Polyfill-safe ends_with check for PHP 7.4+ compatibility.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        $lenHay = strlen($haystack);
        $lenNee = strlen($needle);
        if ($lenNee > $lenHay) {
            return false;
        }
        return substr($haystack, -$lenNee) === $needle;
    }
}
