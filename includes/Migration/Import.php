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
     * Import a site from a ZIP package.
     *
     * This command extracts the package, imports SQL, performs URL/path search-replace,
     * moves uploads/plugins/themes, and imports users. It also adapts the installation
     * for multisite when applicable.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : The exported ZIP file (absolute or relative to ABSPATH).
     *
     * [--new_url=<new_domain>]
     * : The new hostname (scheme optional) to assign to the imported site.
     *
     * [--mysql-single-transaction]
     * : Wrap the SQL import with START TRANSACTION/COMMIT.
     *
     * [--uid_fields=<uid_fields>]
     * : Comma-separated user meta fields that uniquely identify authors (for author remapping).
     *
     * [--verbose]
     * : Display additional details during command execution.
     *
     * ## EXAMPLES
     *
     *  Imports a website from website.zip and changes the domain.
     *  wp rrze-migration import all website.zip --new_url=example.org
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function all($args = [], $assoc_args = [])
    {
        $this->process_args(
            [
                0 => '', // .zip file to import
            ],
            $args,
            [
                'new_url'                  => '',
                'mysql-single-transaction' => false,
                'uid_fields'               => '',
            ],
            $assoc_args
        );

        $verbose = isset($assoc_args['verbose']);
        $assoc_args = $this->assoc_args;

        // Resolve input ZIP path safely (supports absolute or relative).
        $input = $this->args[0] ?? '';
        if ($input === '') {
            WP_CLI::error(__('Missing input file (.zip).', 'rrze-cli'));
        }

        $filename = (strpos($input, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\\\#', $input))
            ? $input
            : rtrim(ABSPATH, '/\\') . '/' . ltrim($input, '/\\');

        if (!is_file($filename) || !is_readable($filename) || !Utils::is_zip_file($filename)) {
            WP_CLI::error(__('The provided file does not appear to be a readable zip file', 'rrze-cli'));
        }

        // Unique temp dir (with trailing slash).
        $temp_dir = rtrim(ABSPATH, '/\\') . '/rrze-migration-' . uniqid('', true) . '/';

        WP_CLI::log(__('Extracting zip package...', 'rrze-cli'));
        Utils::extract($filename, $temp_dir);

        // Required files (first match) and optional folders.
        $site_meta_data = glob($temp_dir . '*.json');
        $users          = glob($temp_dir . '*.csv');
        $sql            = glob($temp_dir . '*.sql');

        $plugins_folder = is_dir($temp_dir . 'wp-content/plugins') ? [$temp_dir . 'wp-content/plugins'] : [];
        $themes_folder  = is_dir($temp_dir . 'wp-content/themes')  ? [$temp_dir . 'wp-content/themes']  : [];
        $uploads_folder = is_dir($temp_dir . 'wp-content/uploads') ? [$temp_dir . 'wp-content/uploads'] : [];

        if (empty($site_meta_data) || empty($users) || empty($sql)) {
            WP_CLI::error(__("There's something wrong with the zip package, unable to find required files", 'rrze-cli'));
        }

        $site_meta_data = json_decode((string) file_get_contents($site_meta_data[0]));
        if (!is_object($site_meta_data) || empty($site_meta_data->url)) {
            WP_CLI::error(__('Invalid site metadata JSON.', 'rrze-cli'));
        }

        $old_url = $site_meta_data->url;

        if (!empty($assoc_args['new_url'])) {
            $site_meta_data->url = $assoc_args['new_url'];
        }

        // Create or select target site.
        if (is_multisite()) {
            $blog_id = $this->create_new_site($site_meta_data);
        } else {
            $blog_id = get_current_blog_id();
        }

        if (!$blog_id) {
            WP_CLI::error(__('Could not get blog ID value', 'rrze-cli'));
        }

        $tables_assoc_args = [
            'blog_id'          => (int) $blog_id,
            'original_blog_id' => isset($site_meta_data->blog_id) ? (int) $site_meta_data->blog_id : 1,
            'old_prefix'       => $site_meta_data->db_prefix ?? '',
            'new_prefix'       => Utils::get_db_prefix((int) $blog_id),
        ];

        // If changing URL, force search-replace in tables() step.
        if (!empty($assoc_args['new_url'])) {
            $tables_assoc_args['new_url'] = $assoc_args['new_url'];
            $tables_assoc_args['old_url'] = $old_url;
        }

        WP_CLI::log(__('Importing tables...', 'rrze-cli'));

        // Optionally wrap SQL import in a single transaction.
        if (!empty($assoc_args['mysql-single-transaction'])) {
            Utils::addTransaction($sql[0]);
        }

        $this->tables([$sql[0]], $tables_assoc_args, $verbose);

        $this->delete_transients($site_meta_data);

        // Users import and author remapping.
        $map_file = rtrim($temp_dir, '/\\') . '/users_map.json';
        $users_assoc_args = [
            'map_file' => $map_file,
            'blog_id'  => (int) $blog_id,
        ];

        WP_CLI::log(__('Moving files...', 'rrze-cli'));

        if (!empty($plugins_folder)) {
            $blog_plugins    = isset($site_meta_data->blog_plugins) ? (array) $site_meta_data->blog_plugins : [];
            $network_plugins = isset($site_meta_data->network_plugins) ? array_keys((array) $site_meta_data->network_plugins) : [];
            $this->move_and_activate_plugins($plugins_folder[0], (array) ($site_meta_data->plugins ?? []), $blog_plugins, $network_plugins);
        }

        if (!empty($uploads_folder)) {
            $this->move_uploads($uploads_folder[0], (int) $blog_id);
        }

        if (!empty($themes_folder)) {
            $this->move_themes($themes_folder[0]);
        }

        WP_CLI::log(__('Importing users...', 'rrze-cli'));
        $this->users([$users[0]], $users_assoc_args, $verbose);

        // Update post authors if a mapping file was generated.
        if (file_exists($map_file)) {
            $postsCommand = new Posts();
            $postsCommand->update_author(
                [$map_file],
                [
                    'blog_id'    => (int) $blog_id,
                    'uid_fields' => $assoc_args['uid_fields'],
                ],
                $verbose
            );
        }

        WP_CLI::log(__('Flushing rewrite rules...', 'rrze-cli'));
        add_action('init', function () use ($blog_id) {
            Utils::maybe_switch_to_blog((int) $blog_id);
            flush_rewrite_rules();
            Utils::maybe_restore_current_blog();
        }, 9999);

        WP_CLI::log(__('Removing temporary files....', 'rrze-cli'));
        Utils::delete_folder($temp_dir);

        WP_CLI::success(
            sprintf(
                /* translators: %s: url */
                __('All done, your new site is available at %s. Remember to flush the cache.', 'rrze-cli'),
                esc_url((string) $site_meta_data->url)
            )
        );
    }

    /**
     * Import users from a CSV file.
     *
     * This command creates a mapping JSON with "old_user_id" => "new_user_id".
     * The mapping can be used to update post authors afterwards.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : The exported users CSV.
     *
     * --map_file=<user_mapping_file>
     * : Path to the JSON mapping file to generate.
     *
     * [--blog_id=<blog_id>]
     * : Target site ID (multisite).
     *
     * ## EXAMPLES
     *
     *     # Imports users from users.csv and creates users_map.json.
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
                0 => '', // .csv to import users
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
        if ($input_file_handler === false) {
            WP_CLI::error(sprintf(__('Can not read the file %s', 'rrze-cli'), $filename));
        }

        $delimiter = ',';

        /**
         * Holds new user IDs by old ID.
         * Example: ['OLD_ID' => 'NEW_ID'].
         */
        $ids_maps       = [];
        $labels         = [];
        $count          = 0;
        $existing_users = 0;
        $line           = 0;

        $this->line(sprintf(__('Parsing %s...', 'rrze-cli'), $filename), $verbose);

        Utils::maybe_switch_to_blog((int) $this->assoc_args['blog_id']);
        wp_suspend_cache_addition(true);

        while (false !== ($data = fgetcsv($input_file_handler, 0, $delimiter))) {
            // Header row.
            if (0 === $line++) {
                $labels = $data;
                continue;
            }

            $user_data = array_combine($labels, $data);
            if ($user_data === false) {
                WP_CLI::warning(__('CSV header/data length mismatch; skipping row.', 'rrze-cli'));
                continue;
            }

            $old_id = $user_data['ID'] ?? null;
            unset($user_data['ID']);

            // Check if user exists by login OR email (email only if not empty).
            $user_exists = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE user_login = %s OR (user_email = %s AND user_email != '');",
                    $user_data['user_login'],
                    $user_data['user_email']
                )
            );
            $user_exists = $user_exists ? (int) $user_exists[0] : false;

            if (!$user_exists) {
                // Only default user fields go through wp_insert_user.
                $default_user_data = [];
                foreach (Export::getUserCSVHeaders() as $key) {
                    if (isset($user_data[$key])) {
                        $default_user_data[$key] = $user_data[$key];
                    }
                }

                $user_meta_data = array_diff_key($user_data, $default_user_data);

                $new_id = wp_insert_user($default_user_data);

                if (!is_wp_error($new_id)) {
                    // Preserve the original hashed password from CSV (if any).
                    $wpdb->update($wpdb->users, ['user_pass' => $user_data['user_pass']], ['ID' => $new_id]);

                    $user = new \WP_User($new_id);

                    // Insert custom meta.
                    foreach ($user_meta_data as $meta_key => $meta_value) {
                        update_user_meta($new_id, $meta_key, maybe_unserialize($meta_value));
                    }

                    /**
                     * Fires before importing custom user data.
                     *
                     * @param array   $user_data The $user_data array.
                     * @param \WP_User $user     The user object.
                     */
                    do_action('rrze_migration/import/user/custom_data_before', $user_data, $user);

                    /**
                     * Filter additional custom user meta to import.
                     *
                     * @param array    $data Custom meta key=>value to set.
                     * @param \WP_User $user The user object.
                     */
                    $custom_user_data = apply_filters('rrze_migration_import_user_data', [], $user);

                    if (!empty($custom_user_data)) {
                        foreach ($custom_user_data as $meta_key => $meta_value) {
                            update_user_meta($new_id, $meta_key, $meta_value);
                        }
                    }

                    /**
                     * Fires after importing custom user data.
                     *
                     * @param array   $user_data The $user_data array.
                     * @param \WP_User $user     The user object.
                     */
                    do_action('rrze_migration/import/user/custom_data_after', $user_data, $user);

                    $count++;
                    if ($old_id !== null) {
                        $ids_maps[(string) $old_id] = (int) $new_id;
                    }

                    if ($is_multisite && !empty($user_data['role'])) {
                        Utils::light_add_user_to_blog((int) $this->assoc_args['blog_id'], (int) $new_id, (string) $user_data['role']);
                    }
                } else {
                    $this->warning(
                        sprintf(
                            /* translators: %1$s: user_login, %2$s: error messages */
                            __('An error has occurred when inserting %1$s: %2$s.', 'rrze-cli'),
                            $user_data['user_login'],
                            implode(', ', $new_id->get_error_messages())
                        ),
                        $verbose
                    );
                }
            } else {
                $this->warning(
                    sprintf(
                        /* translators: %1$s: user_login, %2$d: user_id */
                        __('%1$s exists, using ID (%2$d)...', 'rrze-cli'),
                        $user_data['user_login'],
                        $user_exists
                    ),
                    $verbose
                );

                $existing_users++;
                if ($old_id !== null) {
                    $ids_maps[(string) $old_id] = (int) $user_exists;
                }
                if ($is_multisite && !empty($user_data['role'])) {
                    Utils::light_add_user_to_blog((int) $this->assoc_args['blog_id'], (int) $user_exists, (string) $user_data['role']);
                }
            }

            unset($user_exists, $user_data, $data);
        }

        wp_suspend_cache_addition(false);
        Utils::maybe_restore_current_blog();

        fclose($input_file_handler);

        if (!empty($ids_maps)) {
            // Save mapping file.
            $output_file_handler = fopen($this->assoc_args['map_file'], 'w+');
            if ($output_file_handler) {
                fwrite($output_file_handler, json_encode($ids_maps));
                fclose($output_file_handler);

                $this->success(
                    sprintf(
                        /* translators: %s: filename */
                        __('A map file has been created: %s', 'rrze-cli'),
                        $this->assoc_args['map_file']
                    ),
                    $verbose
                );
            } else {
                WP_CLI::warning(__('Unable to write the map file.', 'rrze-cli'));
            }
        }

        $this->success(
            sprintf(
                /* translators: %1$d: number of users imported, %2$d: number of existing users */
                __('%1$d users have been imported and %2$d users already existed', 'rrze-cli'),
                absint($count),
                absint($existing_users)
            ),
            $verbose
        );
    }

    /**
     * Import SQL tables and adapt them to the current installation.
     *
     * Performs optional DB prefix replacement and URL/uploads path search-replace.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : The exported SQL file.
     *
     * --blog_id=<blog_id>
     * : Target site ID where the tables are imported.
     *
     * --old_prefix=<old_table_prefix>
     * : Old table prefix present in the SQL dump.
     *
     * --new_prefix=<new_table_prefix>
     * : New table prefix to apply before import.
     *
     * [--original_blog_id=<ID>]
     * : Original site ID in the source network (for uploads path rewrite).
     *
     * [--old_url=<old_domain>]
     * : Old domain/host (scheme optional) to search for.
     *
     * [--new_url=<new_domain>]
     * : New domain/host (scheme optional) to replace with.
     *
     * ## EXAMPLES
     *
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
                0 => '', // .sql file to import
            ],
            $args,
            [
                'blog_id'          => '',
                'old_url'          => '',
                'new_url'          => '',
                'old_prefix'       => $wpdb->prefix,
                'new_prefix'       => '',
                'original_blog_id' => 1,
            ],
            $assoc_args
        );

        $filename = $this->args[0];

        if (empty($filename) || !file_exists($filename)) {
            WP_CLI::warning(__('Invalid input file', 'rrze-cli'));
            return;
        }

        if (empty($this->assoc_args['blog_id'])) {
            WP_CLI::warning(__('Please, provide a blog_id ', 'rrze-cli'));
            return;
        }

        // Replace DB prefix in SQL dump if requested.
        if (
            !empty($this->assoc_args['new_prefix']) &&
            !$this->replace_db_prefix($filename, (string) $this->assoc_args['old_prefix'], (string) $this->assoc_args['new_prefix'])
        ) {
            WP_CLI::warning(__('Could not replace the db prefix', 'rrze-cli'));
            return;
        }

        $import = Utils::runcommand('db import', [$filename]);
        if (!isset($import->return_code) || 0 !== $import->return_code) {
            WP_CLI::warning(__('Could not import the database', 'rrze-cli'));
            return;
        }

        $this->log(__('Database imported', 'rrze-cli'), $verbose);

        // Search-replace URLs and uploads paths if provided.
        if (!empty($this->assoc_args['old_url']) && !empty($this->assoc_args['new_url'])) {
            $old_url_raw = (string) $this->assoc_args['old_url'];
            $new_url_raw = (string) $this->assoc_args['new_url'];

            $old_url = Utils::parse_url_for_search_replace($old_url_raw);
            $new_url = Utils::parse_url_for_search_replace($new_url_raw);

            $this->log(sprintf(
                /* translators: %1$s: old_url, %2$s: new_url */
                __('Running search-replace for url: %1$s -> %2$s', 'rrze-cli'),
                $old_url,
                $new_url
            ), $verbose);

            $skipTable = is_multisite() ? $wpdb->base_prefix . 'blogs' : 'wp_blogs';
            $assoc = [
                'precise'     => true,
                'all-tables'  => true,
                'skip-tables' => $skipTable,
            ];

            $code = WP_CLI::launch_self('search-replace', [$old_url, $new_url], $assoc, false, false, ['url' => $new_url_raw]);

            if ($code === 0) {
                $this->log(__('Search and Replace for url has been successfully executed', 'rrze-cli'), $verbose);
            } else {
                WP_CLI::warning(__('Could not run search-replace for url', 'rrze-cli'));
                return;
            }

            // Uploads paths (sites/{id}).
            $this->log(__('Running Search and Replace for uploads paths', 'rrze-cli'), $verbose);

            $from = 'wp-content/uploads';
            $to   = 'wp-content/uploads';

            if (!empty($this->assoc_args['original_blog_id']) && (int) $this->assoc_args['original_blog_id'] > 1) {
                $from = 'wp-content/uploads/sites/' . (int) $this->assoc_args['original_blog_id'];
            }

            if ((int) $this->assoc_args['blog_id'] > 1) {
                $to = 'wp-content/uploads/sites/' . (int) $this->assoc_args['blog_id'];
            }

            if ($from !== $to) {
                $code = WP_CLI::launch_self(
                    'search-replace',
                    [$from, $to],
                    ['all-tables' => true, 'precise' => true],
                    false,
                    false,
                    ['url' => $new_url_raw]
                );

                if ($code === 0) {
                    $this->log(
                        sprintf(
                            /* translators: %1$s: from, %2$s: to */
                            __('Uploads paths have been successfully updated: %1$s -> %2$s', 'rrze-cli'),
                            $from,
                            $to
                        ),
                        $verbose
                    );
                } else {
                    WP_CLI::warning(__('Could not run search-replace for uploads paths', 'rrze-cli'));
                    return;
                }
            }
        }

        Utils::maybe_switch_to_blog((int) $this->assoc_args['blog_id']);

        // Update the user_roles option name to the current table prefix.
        $new_wp_roles_option_key = $wpdb->prefix . 'user_roles';
        $old_wp_roles_option_key = (string) $this->assoc_args['old_prefix'] . 'user_roles';

        $wpdb->update(
            $wpdb->options,
            ['option_name' => $new_wp_roles_option_key],
            ['option_name' => $old_wp_roles_option_key],
            ['%s'],
            ['%s']
        );

        // (Optional) Force home/siteurl to new_url defensively.
        if (!empty($this->assoc_args['new_url'])) {
            update_option('home', (string) $this->assoc_args['new_url']);
            update_option('siteurl', (string) $this->assoc_args['new_url']);
        }

        Utils::maybe_restore_current_blog();
    }

    /**
     * Move plugins into place and (optionally) activate them (site or network).
     *
     * @param string     $plugins_dir      Source plugins directory inside the ZIP.
     * @param array      $plugins          Full plugins list (from get_plugins()).
     * @param array|bool $blog_plugins     Active plugins at blog-level in the source (or []).
     * @param array|bool $network_plugins  Network active plugins in the source (or []).
     */
    private function move_and_activate_plugins($plugins_dir, $plugins, $blog_plugins, $network_plugins)
    {
        if (!is_dir($plugins_dir)) {
            WP_CLI::warning(__('Could not find the plugins folder', 'rrze-cli'));
            return;
        }

        WP_CLI::log(__('Moving Plugins...', 'rrze-cli'));

        $installed_plugins = WP_PLUGIN_DIR;
        $blog_plugins    = is_array($blog_plugins) ? $blog_plugins : [];
        $network_plugins = is_array($network_plugins) ? $network_plugins : [];

        foreach ($plugins as $plugin_name => $plugin) {
            $plugin_folder = dirname($plugin_name);
            $srcFolder = rtrim($plugins_dir, '/\\') . '/' . $plugin_folder;
            $dstFolder = rtrim($installed_plugins, '/\\') . '/' . $plugin_folder;

            // If selective lists were provided, only move/activate those present in either list.
            $selective = !empty($blog_plugins) || !empty($network_plugins);
            if ($selective && !in_array($plugin_name, $blog_plugins, true) && !in_array($plugin_name, $network_plugins, true)) {
                continue;
            }

            if (is_dir($srcFolder) && !is_dir($dstFolder)) {
                WP_CLI::log(sprintf(__('Moving %s to plugins folder', 'rrze-cli'), $plugin_name));
                Utils::move_folder($srcFolder, $dstFolder);
            }

            // Activation (blog-wide or network-wide).
            if (in_array($plugin_name, $blog_plugins, true)) {
                WP_CLI::log(sprintf(__('Activating plugin: %s', 'rrze-cli'), $plugin_name));
                $res = activate_plugin($dstFolder . '/' . basename($plugin_name));
                if (is_wp_error($res)) {
                    WP_CLI::warning(sprintf(__('Activation failed for %1$s: %2$s', 'rrze-cli'), $plugin_name, implode(', ', $res->get_error_messages())));
                }
            } elseif (in_array($plugin_name, $network_plugins, true)) {
                WP_CLI::log(sprintf(__('Activating plugin network-wide: %s', 'rrze-cli'), $plugin_name));
                $res = activate_plugin($dstFolder . '/' . basename($plugin_name), '', true);
                if (is_wp_error($res)) {
                    WP_CLI::warning(sprintf(__('Network activation failed for %1$s: %2$s', 'rrze-cli'), $plugin_name, implode(', ', $res->get_error_messages())));
                }
            }
        }
    }

    /**
     * Move uploads into place for the target site.
     *
     * @param string $uploads_dir
     * @param int    $blog_id
     */
    private function move_uploads($uploads_dir, $blog_id)
    {
        if (!is_dir($uploads_dir)) {
            WP_CLI::warning(__('Could not find the uploads folder', 'rrze-cli'));
            return;
        }

        WP_CLI::log(__('Moving uploads...', 'rrze-cli'));

        Utils::maybe_switch_to_blog((int) $blog_id);
        $dest_uploads_dir = wp_upload_dir();
        Utils::maybe_restore_current_blog();

        Utils::move_folder($uploads_dir, $dest_uploads_dir['basedir']);
    }

    /**
     * Move themes into place (optionally enabling them).
     *
     * @param string $themes_dir
     */
    private function move_themes($themes_dir)
    {
        if (!is_dir($themes_dir)) {
            WP_CLI::warning(__('Could not find the themes folder', 'rrze-cli'));
            return;
        }

        WP_CLI::log(__('Moving Themes...', 'rrze-cli'));

        $installed_themes = rtrim(get_theme_root(), '/\\');
        $it = new \DirectoryIterator($themes_dir);

        foreach ($it as $theme) {
            if ($theme->isDot() || !$theme->isDir()) {
                continue;
            }
            $slug = $theme->getFilename();
            $src  = rtrim($themes_dir, '/\\') . '/' . $slug;
            $dst  = $installed_themes . '/' . $slug;

            if (!is_dir($dst)) {
                WP_CLI::log(sprintf(__('Moving %s to themes folder', 'rrze-cli'), $slug));
                Utils::move_folder($src, $dst);

                // If your environment provides `theme enable`, keep it:
                Utils::runcommand('theme enable', [$slug]);
                // Otherwise consider:
                // Utils::runcommand('theme activate', [$slug]);
            }
        }
    }

    /**
     * Create a new site in a multisite network, if it does not already exist.
     *
     * @param object $meta_data
     * @return false|int Blog ID on success or false on failure.
     */
    private function create_new_site($meta_data)
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($meta_data->url) : parse_url($meta_data->url);
        if (!$parts || empty($parts['host'])) {
            return false;
        }

        $site_id = get_main_network_id();
        $domain  = strtolower($parts['host']);
        $path    = $parts['path'] ?? '/';

        if (domain_exists($domain, $path, $site_id)) {
            return false;
        }

        $blog_id = wp_insert_site([
            'domain'     => $domain,
            'path'       => $path,
            'network_id' => $site_id,
        ]);

        return is_wp_error($blog_id) ? false : (int) $blog_id;
    }

    /**
     * Replace a DB prefix in SQL using a simple token-based approach.
     *
     * @param string $filename      SQL file path.
     * @param string $old_db_prefix The DB prefix to replace (e.g., "wp_").
     * @param string $new_db_prefix The DB prefix to write (e.g., "wp_3_").
     * @return bool
     */
    private function replace_db_prefix($filename, $old_db_prefix, $new_db_prefix)
    {
        $new_prefix = (string) $new_db_prefix;

        if ($new_prefix !== '') {
            // Common SQL tokens where table names appear with backticks (simple case).
            $mysql_cmd = [
                'DROP TABLE IF EXISTS',
                'CREATE TABLE',
                'LOCK TABLES',
                'INSERT INTO',
                'CREATE TABLE IF NOT EXISTS',
                'ALTER TABLE',
                'CONSTRAINT',
                'REFERENCES',
                'RENAME TABLE',
                'DROP VIEW IF EXISTS',
                'CREATE VIEW',
                'ALTER VIEW',
                'TRIGGER',
            ];

            $search = [];
            $replace = [];
            foreach ($mysql_cmd as $cmd) {
                $search[]  = "{$cmd} `{$old_db_prefix}";
                $replace[] = "{$cmd} `{$new_prefix}";
            }

            $file_contents = (string) file_get_contents($filename);
            $file_contents = str_replace($search, $replace, $file_contents);

            if ($file_contents === '') {
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
     * Delete old transients on the target site.
     *
     * @param object $meta_data
     */
    private function delete_transients($meta_data)
    {
        WP_CLI::log(__('Deleting transients...', 'rrze-cli'));
        // --all must be a boolean assoc arg, not a positional.
        Utils::runcommand('transient delete', [], ['all' => true], ['url' => $meta_data->url]);
    }
}
