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
     * Exports a website to a ZIP file.
     *
     * ## OPTIONS
     * 
     * [<outputfile>]
     * : The name of the exported ZIP file.
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

        $verbose = false;

        if (isset($assoc_args['verbose'])) {
            $verbose = true;
        }

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
            'blog_id'         => $blogId
        ];

        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_title($site_data['name']) . '.zip',
            ],
            $args,
            [
                'tables'        => '',
                'custom-tables' => '',
            ],
            $assoc_args
        );

        $zip_file = ABSPATH . $this->args[0];

        $include_plugins = isset($this->assoc_args['plugins']) ? true : false;
        $include_themes  = isset($this->assoc_args['themes']) ? true : false;
        $include_uploads = isset($this->assoc_args['uploads']) ? true : false;

        $users_assoc_args  = [];
        $tables_assoc_args = [
            'tables'        => $this->assoc_args['tables'],
            'custom-tables' => $this->assoc_args['custom-tables'],
        ];

        $users_assoc_args['blog_id']  = $blogId;
        $tables_assoc_args['blog_id'] = $blogId;

        // Adds a random prefix to temporary file names to ensure uniqueness and also for security reasons.
        $rand = bin2hex(random_bytes(8));
        $users_file = 'rrze-migration-' . $rand . '-' . sanitize_title($site_data['name']) . '.csv';
        $tables_file = 'rrze-migration-' . $rand . '-' . sanitize_title($site_data['name']) . '.sql';
        $meta_data_file = 'rrze-migration-' . $rand . '-' . sanitize_title($site_data['name']) . '.json';

        WP_CLI::log(__('Exporting site meta data...', 'rrze-cli'));
        file_put_contents($meta_data_file, wp_json_encode($site_data));

        WP_CLI::log(__('Exporting users...', 'rrze-cli'));
        $this->users(array($users_file), $users_assoc_args, $verbose);

        WP_CLI::log(__('Exporting tables...', 'rrze-cli'));
        $this->tables(array($tables_file), $tables_assoc_args, $verbose);

        // Removing previous $zip_file, if any.
        if (file_exists($zip_file)) {
            unlink($zip_file);
        }

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
            if (get_template_directory() !== get_stylesheet_directory()) {
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

        if (file_exists($users_file)) {
            unlink($users_file);
        }

        if (file_exists($tables_file)) {
            unlink($tables_file);
        }

        if (file_exists($meta_data_file)) {
            unlink($meta_data_file);
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
     * Exports the database tables of a website.
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

        if (empty($this->assoc_args['tables']) && empty($this->assoc_args['custom-tables'])) {
            if (is_multisite()) {
                // Multisite: Export only tables with the current blog prefix (e.g., wp_3_)
                $assoc_args = ['format' => 'csv', 'all-tables-with-prefix' => 1];
                $tables_result = Utils::runcommand('db tables', [], $assoc_args, ['url' => $url]);

                if ($tables_result->return_code === 0) {
                    $all_tables = explode(',', $tables_result->stdout);
                    $prefix = $wpdb->prefix;
                    // Filter tables to include only those with the current blog's prefix
                    $tables = array_filter($all_tables, function ($table) use ($prefix) {
                        return strpos($table, $prefix) === 0;
                    });
                } else {
                    WP_CLI::error(__('Could not retrieve the list of tables.', 'rrze-cli'));
                }
            } else {
                // Single site: Export all tables in the database (no prefix filtering)
                $assoc_args = ['format' => 'csv'];
                $tables_result = Utils::runcommand('db tables', [], $assoc_args, ['url' => $url]);

                if ($tables_result->return_code === 0) {
                    $tables = explode(',', $tables_result->stdout);
                } else {
                    WP_CLI::error(__('Could not retrieve the list of tables.', 'rrze-cli'));
                }
            }
        } else {
            // If user specified tables or custom-tables, use exactly those
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
            $export = Utils::runcommand('db export', [$filename], ['tables' => implode(',', $tables)]);

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
     * Exports all users to a CSV file.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : The name of the exported CSV file.
     * 
     * [--woocomerce]
     * : Include all wc_customer_user (if WooCommerce is installed).
     * 
     * [--usersuffix=<string>]
     * : Optional. Suffix to append to the `user_login` field in the CSV export.
     * 
     * ## EXAMPLES
     * 
     *     # Exports all website users and wc_customer_user with ID=2 to a CSV file.
     *     $ wp rrze-migration export users --woocomerce [--url=website-url]
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function users($args = [], $assoc_args = [], $verbose = true)
    {
        $blogId = get_current_blog_id();

        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_text_field(get_bloginfo('name')) . '.csv',
            ],
            $args,
            [
                'woocomerce' => false,
            ],
            $assoc_args
        );

        $usersuffix = isset($this->assoc_args['usersuffix']) ? $this->assoc_args['usersuffix'] : '';

        $filename  = $this->args[0];
        $delimiter = ',';

        $file_handler = fopen($filename, 'w+');

        if (!$file_handler) {
            WP_CLI::error(__('Impossible to create the file', 'rrze-cli'));
        }

        $headers = self::getUserCSVHeaders();

        $users_args = [
            'fields' => 'all',
        ];

        $users_args['blog_id'] = $blogId;

        $count = 0;
        $users = get_users($users_args);
        $user_data_arr = [];

        // This first foreach will find all users meta stored in the usersmeta table.
        foreach ($users as $user) {
            $role = isset($user->roles[0]) ? $user->roles[0] : '';

            $user_login = $user->data->user_login;
            if (!empty($usersuffix) && substr($usersuffix, 0, 1) === '@') {
                if (strpos($user_login, '@') === false) {
                    $user_login .= $usersuffix;
                }
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

                // User Meta.
                $user->get('first_name'),
                $user->get('last_name'),
                $user->get('nickname'),
                $user->get('url'),
                $user->get('description'),
                $user->get('_application_passwords'),
            ];

            // Keeping arrays consistent, not all users have the same meta, so it's possible to have some users who
            // don't even have a given meta key. It must be ensured that these users have an empty column for these fields.
            if (count($headers) - count($user_data) > 0) {
                $user_temp_data_arr = array_fill(0, count($headers) - count($user_data), '');
                $user_data = array_merge($user_data, $user_temp_data_arr);
            }

            $user_data = array_combine($headers, $user_data);

            $user_meta = get_user_meta($user->data->ID);
            $meta_keys = array_keys($user_meta);

            // Removing all unwanted meta keys.
            foreach ($meta_keys as $user_meta_key) {
                $can_add = true;

                // Checking for unwanted meta keys.
                if (!in_array($user_meta_key, $headers, true)) {
                    $can_add = false;
                }

                if (!$can_add) {
                    unset($user_meta[$user_meta_key]);
                }
            }

            // Get the meta keys again.
            $meta_keys = array_keys($user_meta);

            foreach ($meta_keys as $user_meta_key) {
                $value = $user_meta[$user_meta_key];

                // Get_user_meta always return an array when no $key is passed.
                if (is_array($value) && 1 === count($value)) {
                    $value = $value[0];
                }

                // If it's still an array or object, then we need to serialize.
                if (is_array($value) || is_object($value)) {
                    $value = serialize($value);
                }

                $user_data[$user_meta_key] = $value;
            }

            /**
             * Filters the default set of user data to be exported/imported.
             *
             * @param array
             * @param \WP_User $user The user object.
             */
            $custom_user_data = apply_filters('rrze_migration_export_user_data', [], $user);

            if (!empty($custom_user_data)) {
                $user_data = array_merge($user_data, $custom_user_data);
            }

            if (count(array_values($user_data)) !== count($headers)) {
                WP_CLI::error(__('The headers and data length are not matching', 'rrze-cli'));
            }

            $user_data_arr[] = $user_data;
            $count++;
        }

        // Once all the meta keys of the users are obtained, everything can be saved in a csv file.
        fputcsv($file_handler, $headers, $delimiter);

        foreach ($user_data_arr as $user_data) {
            if (count($headers) - count($user_data) > 0) {
                $user_temp_data_arr = array_fill(0, count($headers) - count($user_data), '');
                $user_data = array_merge(array_values($user_data), $user_temp_data_arr);
            }
            fputcsv($file_handler, $user_data, $delimiter);
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

            // User Meta.
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
}
