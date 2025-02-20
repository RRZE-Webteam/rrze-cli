<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Imports users and tables from a CSV file as well as an entire website from a ZIP file.
 *
 * ## EXAMPLES
 *
 *     # Imports an entire website from a ZIP file.
 *     $ wp rrze-migration import all website.zip
 * 
 * @package RRZE\CLI
 */
class Import extends Command
{
    /**
     * Imports a new website from a zip package.
     * 
     * This command will perform the search-replace as well as
     * the necessary updates to make the new website work with a multisite instance.
     * 
     * ## OPTIONS
     *
     * <inputfile>
     * : The name of the exported ZIP file.
     * 
     * [--blog_id=<blog_id>]
     * : The ID of the website where the content of the ZIP file will be imported. It is required if the import is done in a multisite instance.
     * 
     * [--new_url=<new_domain>]
     * : The new hostname of the website into which the ZIP file content is imported.
     * 
     * [--mysql-single-transaction]
     * : Wrap the exported SQL in a single transaction.
     * 
     * [--uid_fields=<uid_fields>]
     * : User meta field.
     * 
     * [--verbose]
     * : Display additional details during command execution.
     * 
     * ## EXAMPLES
     * 
     *     # Imports a website from the compressed file website.zip.
     *     $ wp rrze-migration import all website.zip
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function all($args = [], $assoc_args = [])
    {
        $this->process_args(
            [
                0 => '', // .zip file to import.
            ],
            $args,
            [
                'blog_id'                  => '',
                'new_url'                  => '',
                'mysql-single-transaction' => false,
                'uid_fields'               => '',
            ],
            $assoc_args
        );

        $is_multisite = is_multisite();

        $verbose = false;

        if (isset($assoc_args['verbose'])) {
            $verbose = true;
        }

        $assoc_args = $this->assoc_args;

        $filename = ABSPATH . '/' . $this->args[0];

        if (!Utils::is_zip_file($filename)) {
            WP_CLI::error(__('The provided file does not appear to be a zip file', 'rrze-cli'));
        }

        $temp_dir = ABSPATH . '/' . 'rrze-migration-' . time() . '/';

        WP_CLI::log(__('Extracting zip package...', 'rrze-cli'));

        // Extract the file to the $temp_dir.
        Utils::extract($filename, $temp_dir);

        // Looks for required (.json, .csv and .sql) files and for the optional folders
        // that can live in the zip package (plugins, themes and uploads).
        $site_meta_data = glob($temp_dir . '*.json');
        $users          = glob($temp_dir . '*.csv');
        $sql            = glob($temp_dir . '*.sql');
        $plugins_folder = glob($temp_dir . 'wp-content/plugins');
        $themes_folder  = glob($temp_dir . 'wp-content/themes');
        $uploads_folder = glob($temp_dir . 'wp-content/uploads');

        if (empty($site_meta_data) || empty($users) || empty($sql)) {
            WP_CLI::error(__("There's something wrong with the zip package, unable to find required files", 'rrze-cli'));
        }

        $site_meta_data = json_decode(file_get_contents($site_meta_data[0]));

        $old_url = $site_meta_data->url;

        if (!empty($assoc_args['new_url'])) {
            $site_meta_data->url = $assoc_args['new_url'];
        }

        if (empty($assoc_args['blog_id']) && $is_multisite) {
            $blog_id = $this->create_new_site($site_meta_data);
        } else if ($is_multisite) {
            $blog_id = (int) $assoc_args['blog_id'];
        } else {
            $blog_id = 1;
        }

        if (!$blog_id) {
            WP_CLI::error(__('Unable to create new site', 'rrze-cli'));
        }

        $tables_assoc_args = [
            'blog_id'          => $blog_id,
            'original_blog_id' => $site_meta_data->blog_id,
            'old_prefix'       => $site_meta_data->db_prefix,
            'new_prefix'       => Utils::get_db_prefix($blog_id),
        ];

        /*
         * If changing URL, then set the proper params to force search-replace in the tables method.
         */
        if (!empty($assoc_args['new_url'])) {
            $tables_assoc_args['new_url'] = esc_url($assoc_args['new_url']);
            $tables_assoc_args['old_url'] = esc_url($old_url);
        }

        WP_CLI::log(__('Importing tables...', 'rrze-cli'));

        /*
         * If the flag --mysql-single-transaction is passed, then the SQL is wrapped with
         * START TRANSACTION and COMMIT to insert in one single transaction.
         */
        if ($assoc_args['mysql-single-transaction']) {
            Utils::addTransaction($sql[0]);
        }

        $this->tables([$sql[0]], $tables_assoc_args, $verbose);

        $this->delete_transients($site_meta_data);

        $map_file = $temp_dir . '/users_map.json';

        $users_assoc_args = [
            'map_file' => $map_file,
            'blog_id'  => $blog_id,
        ];

        WP_CLI::log(__('Moving files...', 'rrze-cli'));

        if (!empty($plugins_folder)) {
            $blog_plugins = isset($site_meta_data->blog_plugins) ? (array) $site_meta_data->blog_plugins : false;
            $network_plugins = isset($site_meta_data->network_plugins) ? array_keys((array) $site_meta_data->network_plugins) : false;
            $this->move_and_activate_plugins($plugins_folder[0], (array) $site_meta_data->plugins, $blog_plugins, $network_plugins);
        }

        if (!empty($uploads_folder)) {
            $this->move_uploads($uploads_folder[0], $blog_id);
        }

        if (!empty($themes_folder)) {
            $this->move_themes($themes_folder[0]);
        }

        WP_CLI::log(__('Importing Users...', 'rrze-cli'));

        $this->users([$users[0]], $users_assoc_args, $verbose);

        if (file_exists($map_file)) {
            $postsCommand = new Posts();

            $postsCommand->update_author(
                [$map_file],
                [
                    'blog_id' => $blog_id,
                    'uid_fields' => $assoc_args['uid_fields'],
                ],
                $verbose
            );
        }

        WP_CLI::log(__('Flushing rewrite rules...', 'rrze-cli'));

        add_action('init', function () use ($blog_id) {
            // Flush the rewrite rules for the newly created site, just in case.
            Utils::maybe_switch_to_blog($blog_id);
            flush_rewrite_rules();
            Utils::maybe_restore_current_blog();
        }, 9999);

        WP_CLI::log(__('Removing temporary files....', 'rrze-cli'));

        Utils::delete_folder($temp_dir);

        WP_CLI::success(sprintf(
            __('All done, your new site is available at %s. Remember to flush the cache (memcache, redis etc).', 'rrze-cli'),
            esc_url($site_meta_data->url)
        ));
    }

    /**
     * Imports all users from a CVS file.
     *
     * This command will create a map file containing the new user_id for each user.
     * The map file updates the post_author with the corresponding new user ID.
     * 
     * ## OPTIONS
     *
     * <inputfile>
     * : The name of the exported CSV file.
     * 
     * --map_file=<user_mapping_file>
     * : The name of the user mapping file in JSON format.
     * 
     * [--blog_id=<blog_id>]
     * : The ID of the website to import.
     * 
     * [--tables=<table_list>]
     * : Comma-separated list of tables to be exported.
     * 
     * [--custom-tables=<custom_table_list>]
     * : Comma-separated list of non-standard tables to be exported.
     * 
     * wp rrze-migration import users <inputfile> --map_file=<user_mapping_file> [--blog_id=<blog_id>]
     * 
     * ## EXAMPLES
     * 
     *     # Imports users from the users.csv file based on the mapping in the users_map.json file.
     *     $ wp rrze-migration import users users.csv --map_file=users_map.json
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function users($args = [], $assoc_args = [], $verbose = true)
    {
        global $wpdb;

        $is_multisite = is_multisite();

        $this->process_args(
            [
                0 => '', // .csv to import users.
            ],
            $args,
            [
                'blog_id'  => 1,
                'map_file' => 'ids_maps.json',
            ],
            $assoc_args
        );


        $filename = $this->args[0];

        if (empty($filename) || !file_exists($filename)) {
            WP_CLI::error(__('Invalid input file', 'rrze-cli'));
        }

        $input_file_handler = fopen($filename, 'r');

        $delimiter = ',';

        /**
         * This array will hold the new id for each old id.
         *
         * Example:
         * ['OLD_ID' => 'NEW_ID'];
         */
        $ids_maps       = [];
        $labels         = [];
        $count          = 0;
        $existing_users = 0;

        if (false !== $input_file_handler) {
            $this->line(sprintf(__('Parsing %s...', 'rrze-cli'), $filename), $verbose);

            $line = 0;

            Utils::maybe_switch_to_blog($this->assoc_args['blog_id']);

            wp_suspend_cache_addition(true);
            while (false !== ($data = fgetcsv($input_file_handler, 0, $delimiter))) {
                // Read the labels and skip.
                if (0 === $line++) {
                    $labels = $data;
                    continue;
                }

                $user_data = array_combine($labels, $data);

                $old_id = $user_data['ID'];
                unset($user_data['ID']);

                $user_exists = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->users} WHERE user_login = %s OR (user_email = %s AND user_email != '');",
                        $user_data['user_login'],
                        $user_data['user_email']
                    )
                );

                $user_exists = $user_exists ? $user_exists[0] : false;

                if (!$user_exists) {

                    /*
                     * wp_insert_users accepts only the default user meta keys.
                     */
                    $default_user_data = [];
                    foreach (Export::getCSVHeaders() as $key) {
                        if (isset($user_data[$key])) {
                            $default_user_data[$key] = $user_data[$key];
                        }
                    }

                    // All custom user meta data.
                    $user_meta_data = array_diff_assoc($user_data, $default_user_data);

                    $new_id = wp_insert_user($default_user_data);

                    if (!is_wp_error($new_id)) {
                        $wpdb->update($wpdb->users, ['user_pass' => $user_data['user_pass']], ['ID' => $new_id]);

                        $user = new \WP_User($new_id);

                        // Inserts all custom meta data.
                        foreach ($user_meta_data as $meta_key => $meta_value) {
                            update_user_meta($new_id, $meta_key, maybe_unserialize($meta_value));
                        }

                        /**
                         * Fires before exporting the custom user data.
                         *
                         * @param array  $user_data The $user_data array.
                         * @param object $user      The user object \WP_User.
                         */
                        do_action('rrze_migration/import/user/custom_data_before', $user_data, $user);

                        /**
                         * Filters the default set of user data to be exported/imported.
                         *
                         * @param array
                         * @param object $user The user object \WP_User.
                         */
                        $custom_user_data = apply_filters('rrze_migration/export/user/data', [], $user);

                        if (!empty($custom_user_data)) {
                            foreach ($custom_user_data as $meta_key => $meta_value) {
                                if (isset($user_data[$meta_key])) {
                                    update_user_meta($new_id, $meta_key, sanitize_text_field($meta_value));
                                }
                            }
                        }

                        /**
                         * Fires after exporting the custom user data.
                         *
                         * @param array  $user_data The $user_data array.
                         * @param object $user      The user object \WP_User.
                         */
                        do_action('rrze_migration/import/user/custom_data_after', $user_data, $user);

                        $count++;
                        $ids_maps[$old_id] = $new_id;
                        if ($is_multisite) {
                            Utils::light_add_user_to_blog($this->assoc_args['blog_id'], $new_id, $user_data['role']);
                        }
                    } else {
                        $this->warning(sprintf(
                            __('An error has occurred when inserting %s: %s.', 'rrze-cli'),
                            $user_data['user_login'],
                            implode(', ', $new_id->get_error_messages())
                        ), $verbose);
                    }
                } else {
                    $this->warning(sprintf(
                        __('%s exists, using his ID (%d)...', 'rrze-cli'),
                        $user_data['user_login'],
                        $user_exists
                    ), $verbose);

                    $existing_users++;
                    $ids_maps[$old_id] = $user_exists;
                    if ($is_multisite) {
                        Utils::light_add_user_to_blog($this->assoc_args['blog_id'], $user_exists, $user_data['role']);
                    }
                }

                unset($user_exists);
                unset($user_data);
                unset($data);
            }

            wp_suspend_cache_addition(false);

            Utils::maybe_restore_current_blog();

            if (!empty($ids_maps)) {
                // Saving the ids_maps to a file.
                $output_file_handler = fopen($this->assoc_args['map_file'], 'w+');
                fwrite($output_file_handler, json_encode($ids_maps));
                fclose($output_file_handler);

                $this->success(sprintf(
                    __('A map file has been created: %s', 'rrze-cli'),
                    $this->assoc_args['map_file']
                ), $verbose);
            }

            $this->success(sprintf(
                __('%d users have been imported and %d users already existed', 'rrze-cli'),
                absint($count),
                absint($existing_users)
            ), $verbose);
        } else {
            WP_CLI::error(sprintf(
                __('Can not read the file %s', 'rrze-cli'),
                $filename
            ));
        }
    }

    /**
     * Imports the tables from a website.
     * 
     * This command will perform the search-replace as well as
     * the necessary updates to make the new tables work with a multisite instance.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : The name of the exported SQL file.
     * 
     * --blog_id=<blog_id>
     * : The ID of the website where the tables are to be imported.
     * 
     * --old_prefix=<old_table_prefix>
     * : The old table prefix of the exported website tables.
     * 
     * --new_prefix=<new_table_prefix>
     * : The new table prefix of the tables to be imported.
     * 
     * [--original_blog_id=<ID>]
     * : The original ID of the website from which the tables were exported.
     * 
     * [--old_url=<old_domain>]
     * : The hostname of the website from which the tables were exported.
     * 
     * [--new_url=<new_domain>]
     * : The new hostname of the website into which the tables were imported.
     * 
     * ## EXAMPLES
     * 
     *     # Imports the tables from the website.sql file with prefix wp_ and hostname old.name to the website with hostname new.domain.
     *     $ wp rrze-migration import tables website.sql --old_prefix=wp_ --old_url=old.domain --new_url=new.domain
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
                0 => '', // .sql file to import.
            ],
            $args,
            [
                'blog_id'    => '',
                'old_url'    => '',
                'new_url'    => '',
                'old_prefix' => $wpdb->prefix,
                'new_prefix' => '',
            ],
            $assoc_args
        );

        $filename = $this->args[0];

        if (empty($filename) || !file_exists($filename)) {
            WP_CLI::error(__('Invalid input file', 'rrze-cli'));
        }

        if (empty($this->assoc_args['blog_id'])) {
            WP_CLI::error(__('Please, provide a blog_id ', 'rrze-cli'));
        }

        // Replaces the db prefix and saves back the modifications to the sql file.
        if (
            !empty($this->assoc_args['new_prefix']) &&
            !$this->replace_db_prefix($filename, $this->assoc_args['old_prefix'], $this->assoc_args['new_prefix'])
        ) {
            return;
        }

        $import = Utils::runcommand('db import', [$filename]);

        if (0 === $import->return_code) {
            $this->log(__('Database imported', 'rrze-cli'), $verbose);

            // Perform search and replace.
            if (!empty($this->assoc_args['old_url']) && !empty($this->assoc_args['new_url'])) {
                $this->log(__('Running search-replace', 'rrze-cli'), $verbose);

                $old_url = Utils::parse_url_for_search_replace($this->assoc_args['old_url']);
                $new_url = Utils::parse_url_for_search_replace($this->assoc_args['new_url']);

                // $search_replace = Utils::runcommand('search-replace', [$old_url, $new_url], [], ['url' => $new_url]);
                $search_replace = WP_CLI::launch_self(
                    'search-replace',
                    [
                        $old_url,
                        $new_url,
                    ],
                    ['skip-tables' => 'wp_blogs'],
                    false,
                    false,
                    ['url' => $new_url]
                );

                if (0 === $search_replace) {
                    $this->log(__('Search and Replace has been successfully executed', 'rrze-cli'), $verbose);
                }

                $this->log(__('Running Search and Replace for uploads paths', 'rrze-cli'), $verbose);

                $from = $to = 'wp-content/uploads';

                if (isset($this->assoc_args['original_blog_id']) && $this->assoc_args['original_blog_id'] > 1) {
                    $from = 'wp-content/uploads/sites/' . (int) $this->assoc_args['original_blog_id'];
                }

                if ($this->assoc_args['blog_id'] > 1) {
                    $to = 'wp-content/uploads/sites/' . (int) $this->assoc_args['blog_id'];
                }

                if ($from && $to) {

                    $search_replace = WP_CLI::launch_self(
                        'search-replace',
                        [$from, $to],
                        [],
                        false,
                        false,
                        ['url' => $new_url]
                    );

                    if (0 === $search_replace) {
                        $this->log(sprintf(__('Uploads paths have been successfully updated: %s -> %s', 'rrze-cli'), $from, $to), $verbose);
                    }
                }
            }

            Utils::maybe_switch_to_blog((int) $this->assoc_args['blog_id']);

            // Update the new tables to work properly with multisite.
            $new_wp_roles_option_key = $wpdb->prefix . 'user_roles';
            $old_wp_roles_option_key = $this->assoc_args['old_prefix'] . 'user_roles';

            // Updating user_roles option key.
            $wpdb->update(
                $wpdb->options,
                [
                    'option_name' => $new_wp_roles_option_key,
                ],
                [
                    'option_name' => $old_wp_roles_option_key,
                ],
                [
                    '%s',
                ],
                [
                    '%s',
                ]
            );

            Utils::maybe_restore_current_blog();
        }
    }

    /**
     * Moves the plugins to the right location.
     *
     * @param string $plugins_dir
     * @param array|bool $blog_plguins
     * @param array|bool $network_plugins
     */
    private function move_and_activate_plugins($plugins_dir, $plugins, $blog_plugins, $network_plugins)
    {
        if (file_exists($plugins_dir)) {
            WP_CLI::log(__('Moving Plugins...', 'rrze-cli'));
            $installed_plugins = WP_PLUGIN_DIR;
            $check_plugins        = false !== $blog_plugins && false !== $network_plugins;
            foreach ($plugins as $plugin_name => $plugin) {
                $plugin_folder = dirname($plugin_name);
                $fullPluginPath = $plugins_dir . '/' . $plugin_folder;
                if (
                    $check_plugins &&  !in_array($plugin_name, $blog_plugins, true) &&
                    !in_array($plugin_name, $network_plugins, true)
                ) {
                    continue;
                }

                if (!file_exists($installed_plugins . '/' . $plugin_folder)) {
                    WP_CLI::log(sprintf(__('Moving %s to plugins folder'), $plugin_name));
                    rename($fullPluginPath, $installed_plugins . '/' . $plugin_folder);
                }

                if ($check_plugins && in_array($plugin_name, $blog_plugins, true)) {
                    WP_CLI::log(sprintf(__('Activating plugin: %s '), $plugin_name));
                    activate_plugin($installed_plugins . '/' . $plugin_name);
                } else if ($check_plugins && in_array($plugin_name, $network_plugins, true)) {
                    WP_CLI::log(sprintf(__('Activating plugin network-wide: %s '), $plugin_name));
                    activate_plugin($installed_plugins . '/' . $plugin_name, '', true);
                }
            }
        }
    }

    /**
     * Moves the uploads folder to the right location.
     *
     * @param string $uploads_dir
     * @param int    $blog_id
     */
    private function move_uploads($uploads_dir, $blog_id)
    {
        if (file_exists($uploads_dir)) {
            WP_CLI::log(__('Moving Uploads...', 'rrze-cli'));
            Utils::maybe_switch_to_blog($blog_id);
            $dest_uploads_dir = wp_upload_dir();
            Utils::maybe_restore_current_blog();
            Utils::move_folder($uploads_dir, $dest_uploads_dir['basedir']);
        }
    }

    /**
     * Moves the themes to the right location.
     *
     * @param string $themes_dir
     */
    private function move_themes($themes_dir)
    {
        if (file_exists($themes_dir)) {
            WP_CLI::log(__('Moving Themes...', 'rrze-cli'));
            $themes = new \DirectoryIterator($themes_dir);
            $installed_themes = get_theme_root();

            foreach ($themes as $theme) {
                if ($theme->isDir()) {
                    $fullPluginPath = $themes_dir . '/' . $theme->getFilename();

                    if (!file_exists($installed_themes . '/' . $theme->getFilename())) {
                        WP_CLI::log(sprintf(__('Moving %s to themes folder'), $theme->getFilename()));
                        rename($fullPluginPath, $installed_themes . '/' . $theme->getFilename());

                        Utils::runcommand('theme enable', [$theme->getFilename()]);
                    }
                }
            }
        }
    }

    /**
     * Creates a new site within multisite.
     *
     * @param object $meta_data
     * @return bool|false|int
     */
    private function create_new_site($meta_data)
    {
        $parsed_url = parse_url(esc_url($meta_data->url));
        $site_id = get_main_network_id();

        $parsed_url['path'] = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

        if (domain_exists($parsed_url['host'], $parsed_url['path'], $site_id)) {
            return false;
        }

        $blog_id = wp_insert_site([
            'domain' => $parsed_url['host'],
            'path' => $parsed_url['path'],
            'network_id' => $site_id
        ]);

        if (!$blog_id) {
            return false;
        }

        return $blog_id;
    }

    /**
     * Replaces the db_prefix with a new one using sed.
     *
     * @param string $filename      The filename of the sql file to which the db prefix should be replaced.
     * @param string $old_db_prefix The db prefix to be replaced.
     * @param string $new_db_prefix The new db prefix.
     */
    private function replace_db_prefix($filename, $old_db_prefix, $new_db_prefix)
    {
        $new_prefix = $new_db_prefix;

        if (!empty($new_prefix)) {
            $mysql_cmd = [
                'DROP TABLE IF EXISTS',
                'CREATE TABLE',
                'LOCK TABLES',
                'INSERT INTO',
                'CREATE TABLE IF NOT EXISTS',
                'ALTER TABLE',
                'CONSTRAINT',
                'REFERENCES',
            ];

            $search = [];
            $replace = [];
            foreach ($mysql_cmd as $cmd) {
                $search[] = "{$cmd} `{$old_db_prefix}";
                $replace[] = "{$cmd} `{$new_prefix}";
            }

            $file_contents = (string) file_get_contents($filename);
            $file_contents = str_replace($search, $replace, $file_contents);
            if (empty($file_contents)) {
                WP_CLI::warning(__('It was not possible to do a search and replace of the SQL file.', 'rrze-cli'));
                return false;
            }
            if (file_put_contents($filename, $file_contents) === false) {
                WP_CLI::warning(__('Could not overwrite SQL file.', 'rrze-cli'));
                return false;
            }
        }

        return true;
    }

    /**
     * Delete old transients.
     *
     * @param object $meta_data
     */
    private function delete_transients($meta_data)
    {
        WP_CLI::log(__('Deleting transients...', 'rrze-cli'));
        Utils::runcommand('transient delete', ['--all'], [], ['url' => $meta_data->url]);
    }
}
