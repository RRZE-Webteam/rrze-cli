<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Exports an entire website into a zip package.
 *
 * ## EXAMPLES
 *
 *     # Exports users, tables, plugins folder, themes folder and the uploads folder to a ZIP file.
 *     $ wp rrze-migration export all website.zip --plugins --themes --uploads
 * 
 * @package RRZE\CLI
 */
class Export extends Command
{
    /**
     * Returns the Headers (first row) for the CSV export file.
     *
     * @return array
     * @internal
     */
    public static function getCSVHeaders()
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
            'rich_editing',
            'admin_color',
            'show_admin_bar_front',
            'first_name',
            'last_name',
            'nickname',
            'aim',
            'yim',
            'jabber',
            'description',
        ];

        $custom_headers = apply_filters('rrze_migration/export/user/headers', []);

        if (!empty($custom_headers)) {
            $headers = array_merge($headers, $custom_headers);
        }

        return $headers;
    }

    /**
     * Exports the database tables of a website.
     * 
     * ## OPTIONS
     *
     * <outputfile>
     * : The name of the exported SQL file.
     * 
     * [--blog_id=<blog_id>]
     * : The ID of the website to export.
     * 
     * [--tables=<table_list>]
     * : Comma-separated list of tables to be exported.
     * 
     * [--custom-tables=<custom_table_list>]
     * : Comma-separated list of non-standard tables to be exported.
     * 
     * ## EXAMPLES
     * 
     *     # Exports all standard tables of a website to output.sql file.
     *     $ wp rrze-migration export tables output.sql
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
                0 => '', // output file name
            ],
            $args,
            [
                'blog_id'       => 1,
                'tables'        => '',
                'custom-tables' => '',
            ],
            $assoc_args
        );

        $filename = $this->args[0];

        if (isset($this->assoc_args['blog_id'])) {
            $url = get_home_url((int) $this->assoc_args['blog_id']);
        }

        /*
         * If the tables to be exported have not been provided, obtain them automatically.
         */
        if (empty($this->assoc_args['tables'])) {
            $assoc_args = ['format' => 'csv'];

            if (empty($this->assoc_args['custom-tables']) && ($this->assoc_args['blog_id'] != 1 || !is_multisite())) {
                $assoc_args['all-tables-with-prefix'] = 1;
            }

            $tables = Utils::runcommand('db tables', [], $assoc_args, ['url' => $url]);

            if (0 === $tables->return_code) {
                $tables = $tables->stdout;
                $tables = explode(',', $tables);

                $tables_to_remove = [
                    $wpdb->prefix . 'users',
                    $wpdb->prefix . 'usermeta',
                    $wpdb->prefix . 'blog_versions',
                    $wpdb->prefix . 'blogs',
                    $wpdb->prefix . 'site',
                    $wpdb->prefix . 'sitemeta',
                    $wpdb->prefix . 'registration_log',
                    $wpdb->prefix . 'signups',
                    $wpdb->prefix . 'sitecategories',
                ];

                foreach ($tables as $key => &$table) {
                    $table = trim($table);

                    if (in_array($table, $tables_to_remove)) {
                        unset($tables[$key]);
                    }
                }
            }

            if (!empty($this->assoc_args['custom-tables'])) {
                $non_default_tables = explode(',', $this->assoc_args['custom-tables']);

                $tables = array_unique(array_merge($tables, $non_default_tables));
            }
        } else {
            // Get the user supplied tables list.
            $tables = explode(',', $this->assoc_args['tables']);
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
     * <outputfile>
     * : The name of the exported CSV file.
     * 
     * [--blog_id=<blog_id>]
     * : The ID of the website to export.
     * 
     * [--woocomerce]
     * : Include all wc_customer_user (if WooCommerce is installed).
     * 
     * ## EXAMPLES
     * 
     *     # Exports all website users and wc_customer_user with ID=2 to a CSV file.
     *     $ wp rrze-migration export users output.csv --blog_id=2 --woocomerce
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function users($args = [], $assoc_args = [], $verbose = true)
    {
        $this->process_args(
            [
                0 => 'users.csv',
            ],
            $args,
            [
                'blog_id' => '',
            ],
            $assoc_args
        );

        $filename  = $this->args[0];
        $delimiter = ',';

        $file_handler = fopen($filename, 'w+');

        if (!$file_handler) {
            WP_CLI::error(__('Impossible to create the file', 'rrze-cli'));
        }

        $headers = self::getCSVHeaders();

        $users_args = [
            'fields' => 'all',
        ];

        if (!empty($this->assoc_args['blog_id'])) {
            $users_args['blog_id'] = (int) $this->assoc_args['blog_id'];
        }

        $excluded_meta_keys = [
            'session_tokens' => true,
            'primary_blog'   => true,
            'source_domain'  => true,
        ];

        /*
         * Do not include meta keys that depend on the db prefix.
         */
        $excluded_meta_keys_regex = [
            '/capabilities$/',
            '/user_level$/',
            '/dashboard_quick_press_last_post_id$/',
            '/user-settings$/',
            '/user-settings-time$/',
        ];

        $count = 0;
        $users = get_users($users_args);
        $user_data_arr = [];

        /*
         * This first foreach will find all users meta stored in the usersmeta table.
         */
        foreach ($users as $user) {
            $role = isset($user->roles[0]) ? $user->roles[0] : '';

            $user_data = [
                // General Info.
                $user->data->ID,
                $user->data->user_login,
                $user->data->user_pass,
                $user->data->user_nicename,
                $user->data->user_email,
                $user->data->user_url,
                $user->data->user_registered,
                $role,
                $user->data->user_status,
                $user->data->display_name,

                // User Meta.
                $user->get('rich_editing'),
                $user->get('admin_color'),
                $user->get('show_admin_bar_front'),
                $user->get('first_name'),
                $user->get('last_name'),
                $user->get('nickname'),
                $user->get('aim'),
                $user->get('yim'),
                $user->get('jabber'),
                $user->get('description'),
            ];

            /*
             * Keeping arrays consistent, not all users have the same meta, so it's possible to have some users who
             * don't even have a given meta key. It must be ensured that these users have an empty column for these fields.
             */
            if (count($headers) - count($user_data) > 0) {
                $user_temp_data_arr = array_fill(0, count($headers) - count($user_data), '');
                $user_data = array_merge($user_data, $user_temp_data_arr);
            }

            $user_data = array_combine($headers, $user_data);

            $user_meta = get_user_meta($user->data->ID);
            $meta_keys = array_keys($user_meta);

            /*
             * Removing all unwanted meta keys.
             */
            foreach ($meta_keys as $user_meta_key) {
                if (!isset($excluded_meta_keys[$user_meta_key])) {
                    $can_add = true;

                    /*
                     * Checking for unwanted meta keys.
                     */
                    foreach ($excluded_meta_keys_regex as $regex) {
                        if (preg_match($regex, $user_meta_key)) {
                            $can_add = false;
                        }
                    }

                    if (!$can_add) {
                        unset($user_meta[$user_meta_key]);
                    }
                } else {
                    unset($user_meta[$user_meta_key]);
                }
            }

            // Get the meta keys again.
            $meta_keys = array_keys($user_meta);

            foreach ($meta_keys as $user_meta_key) {
                $value = $user_meta[$user_meta_key];

                // Get_user_meta always return an array whe no $key is passed.
                if (is_array($value) && 1 === count($value)) {
                    $value = $value[0];
                }

                // If it's still an array or object, then we need to serialize.
                if (is_array($value) || is_object($value)) {
                    $value = serialize($value);
                }

                $user_data[$user_meta_key] = $value;
            }

            // Adding the meta_keys that aren't in the $headers variable to the $headers variable.
            $diff    = array_diff($meta_keys, $headers);
            $headers = array_merge($headers, $diff);

            /**
             * Filters the default set of user data to be exported/imported.
             *
             * @param array
             * @param \WP_User $user The user object.
             */
            $custom_user_data = apply_filters('rrze_migration/export/user/data', [], $user);

            if (!empty($custom_user_data)) {
                $user_data = array_merge($user_data, $custom_user_data);
            }

            if (count(array_values($user_data)) !== count($headers)) {
                WP_CLI::error(__('The headers and data length are not matching', 'rrze-cli'));
            }

            $user_data_arr[] = $user_data;
            $count++;
        }

        /*
         * Once all the meta keys of the users are obtained, everything can be saved in a csv file.
         */
        fputcsv($file_handler, $headers, $delimiter);

        foreach ($user_data_arr as $user_data) {
            if (count($headers) - count($user_data) > 0) {
                $user_temp_data_arr = array_fill(0, count($headers) - count($user_data), '');
                $user_data = array_merge(array_values($user_data), $user_temp_data_arr);
            }
            fputcsv($file_handler, $user_data, $delimiter);
        }

        fclose($file_handler);

        $this->success(sprintf(
            __('%d users have been exported', 'rrze-cli'),
            absint($count)
        ), $verbose);
    }

    /**
     * Exports a website to a ZIP file.
     *
     * ## OPTIONS
     *
     * <outputfile>
     * : The name of the exported ZIP file.
     * 
     * [--blog_id=<blog_id>]
     * : The ID of the website to export.
     * 
     * [--tables=<table_list>]
     * : Comma-separated list of tables to be exported.
     * 
     * [--custom-tables=<custom_table_list>]
     * : Comma-separated list of non-standard tables to be exported.
     * 
     * [--plugins]
     * : Includes the whole plugins directory.
     * 
     * [--themes]
     * : Includes website theme/child directory.
     * 
     * [--uploads]
     * : Includes website uploads directory.
     * 
     * [--verbose]
     * : Display additional details during command execution.
     * 
     * ## EXAMPLES
     * 
     *     # Exports a website to website.zip file.
     *     $ wp rrze-migration export all website.zip
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function all($args = [], $assoc_args = [])
    {
        global $wpdb;

        $switched = false;

        if (isset($assoc_args['blog_id'])) {
            Utils::maybe_switch_to_blog((int) $assoc_args['blog_id']);
            $switched = true;
        }

        $verbose = false;

        if (isset($assoc_args['verbose'])) {
            $verbose = true;
        }

        $site_data = [
            'url'             => esc_url(home_url()),
            'name'            => sanitize_text_field(get_bloginfo('name')),
            'admin_email'     => sanitize_text_field(get_bloginfo('admin_email')),
            'site_language'   => sanitize_text_field(get_bloginfo('language')),
            'db_prefix'       => $wpdb->prefix,
            'plugins'         => get_plugins(),
            'blog_plugins'    => get_option('active_plugins'),
            'network_plugins' => is_multisite() ? get_site_option('active_sitewide_plugins') : [],
            'blog_id'         => 1
        ];

        if (isset($assoc_args['blog_id'])) {
            $site_data['blog_id'] = get_current_blog_id();
        }

        $this->process_args(
            [
                0 => 'rrze-migration-' . sanitize_title($site_data['name']) . '.zip',
            ],
            $args,
            [
                'blog_id'       => false,
                'tables'        => '',
                'custom-tables' => '',
            ],
            $assoc_args
        );

        $zip_file = $this->args[0];

        $include_plugins = isset($this->assoc_args['plugins']) ? true : false;
        $include_themes  = isset($this->assoc_args['themes']) ? true : false;
        $include_uploads = isset($this->assoc_args['uploads']) ? true : false;

        $users_assoc_args  = [];
        $tables_assoc_args = [
            'tables'        => $this->assoc_args['tables'],
            'custom-tables' => $this->assoc_args['custom-tables'],
        ];

        if ($this->assoc_args['blog_id']) {
            $users_assoc_args['blog_id']  = (int) $this->assoc_args['blog_id'];
            $tables_assoc_args['blog_id'] = (int) $this->assoc_args['blog_id'];
        }

        /*
         * Adds a random prefix to temporary file names to ensure uniqueness and also for security reasons.
         */
        $rand = bin2hex(random_bytes(8));
        $users_file = 'rrze-migration-' . $rand . sanitize_title($site_data['name']) . '.csv';
        $tables_file = 'rrze-migration-' . $rand . sanitize_title($site_data['name']) . '.sql';
        $meta_data_file = 'rrze-migration-' . $rand . sanitize_title($site_data['name']) . '.json';

        WP_CLI::log(__('Exporting site meta data...', 'rrze-cli'));
        file_put_contents($meta_data_file, wp_json_encode($site_data));

        WP_CLI::log(__('Exporting users...', 'rrze-cli'));
        $this->users(array($users_file), $users_assoc_args, $verbose);

        WP_CLI::log(__('Exporting tables', 'rrze-cli'));
        $this->tables(array($tables_file), $tables_assoc_args, $verbose);

        $zip = null;

        /*
         * Removing previous $zip_file, if any.
         */
        if (file_exists($zip_file)) {
            unlink($zip_file);
        }

        $files_to_zip = [
            $users_file     => $users_file,
            $tables_file    => $tables_file,
            $meta_data_file => $meta_data_file,
        ];

        if ($include_plugins) {
            $files_to_zip['wp-content/plugins'] = WP_PLUGIN_DIR;
        }

        if ($include_themes) {
            $theme_dir = get_template_directory();
            $files_to_zip['wp-content/themes/' . basename($theme_dir)] = $theme_dir;
            if (get_template_directory() !== get_stylesheet_directory()) {
                $child_theme_dir = get_stylesheet_directory();
                $files_to_zip['wp-content/themes/' . basename($child_theme_dir)] = $child_theme_dir;
            }
        }

        if ($include_uploads) {
            $upload_dir = wp_upload_dir();
            $files_to_zip['wp-content/uploads'] = $upload_dir['basedir'];
        }

        try {
            WP_CLI::log(__('Zipping files....', 'rrze-cli'));
            $zip = Utils::zip($zip_file, $files_to_zip);
        } catch (\Exception $e) {
            WP_CLI::warning($e->getMessage());
        }

        if (file_exists($users_file)) {
            unlink($users_file);
        }

        if (file_exists($tables_file)) {
            unlink($tables_file);
        }

        if (file_exists($meta_data_file)) {
            unlink($meta_data_file);
        }

        if ($zip !== null) {
            WP_CLI::success(sprintf(__('A zip file named %s has been created', 'rrze-cli'), $zip_file));
        }

        if ($switched) {
            Utils::maybe_restore_current_blog();
        }
    }
}
