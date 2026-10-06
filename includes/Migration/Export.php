<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use RuntimeException;
use WP_CLI;

/** Exports site-owned data for migration to a new multisite site. */
class Export extends Command
{
    public function __construct(private readonly ?\Closure $review = null)
    {
    }

    /**
     * Exports a website to a new ZIP file; existing files are never replaced.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : Output filename.
     * [--tables=<tables>]
     * : Explicit site-owned tables; a complete package must include the core tables.
     * [--custom-tables=<tables>]
     * : Additional site-owned tables, including explicitly selected main-site tables.
     * [--uploads]
     * : Include the site's uploads.
     * [--plugins]
     * : Unsupported; provide plugins separately in the destination.
     * [--themes]
     * : Unsupported; provide themes separately in the destination.
     * [--usersuffix=<suffix>]
     * : Unsupported; SSO logins must not be changed.
     * [--verbose]
     * : Show progress details.
     */
    public function all($args = [], $assoc_args = [])
    {
        global $wpdb;
        $workspace = null;
        $output = null;
        $reserved = false;
        $error = null;
        try {
            $this->validate_options($assoc_args);
            $tables = $this->select_tables($assoc_args);
            if (array_diff($wpdb->tables('blog'), $tables)) {
                throw new RuntimeException('A complete migration package must contain every core table of the source site.');
            }
            $output = $this->output_path($args[0] ?? 'rrze-migration-' . sanitize_title(get_bloginfo('name')) . '.zip');
            if (file_exists($output) || is_link($output)) {
                throw new RuntimeException('The output file already exists. Choose a new filename.');
            }
            if ($this->review !== null && !($this->review)([
                'source' => home_url(), 'site_id' => get_current_blog_id(), 'tables' => $tables,
                'output' => $output, 'uploads' => isset($assoc_args['uploads']),
            ])) {
                throw new RuntimeException('Export cancelled. No output file was created.');
            }
            fclose(Files::output($output));
            $reserved = true;
            $workspace = Files::workspace();
            $meta = [
                'url' => home_url(), 'name' => get_bloginfo('name'), 'admin_email' => get_bloginfo('admin_email'),
                'site_language' => get_bloginfo('language'), 'db_prefix' => $wpdb->prefix,
                'blog_id' => get_current_blog_id(), 'tables' => $tables, 'uploads_included' => isset($assoc_args['uploads']),
            ];
            WP_CLI::log('Exporting users and site tables...');
            $this->write_users($workspace . '/users.csv');
            $this->write_tables($workspace . '/tables.sql', $tables);
            $files = ['users.csv' => $workspace . '/users.csv', 'tables.sql' => $workspace . '/tables.sql'];
            if (isset($assoc_args['uploads'])) {
                $uploads = wp_upload_dir(null, false);
                if ($uploads['error']) {
                    throw new RuntimeException('Cannot read the source uploads directory.');
                }
                if (is_dir($uploads['basedir'])) {
                    $files['wp-content/uploads'] = $uploads['basedir'];
                }
            }
            Package::write($output, $files, $meta, $workspace);
        } catch (\Throwable $failure) {
            $error = $failure->getMessage();
        } finally {
            if ($workspace !== null) {
                try {
                    Files::remove($workspace);
                } catch (\Throwable $failure) {
                    $error = ($error ? $error . ' ' : '') . 'Could not clean up the private migration workspace: ' . $workspace;
                }
            }
            if ($error !== null && $reserved && !unlink($output)) {
                $error .= ' Could not remove the incomplete output file.';
            }
        }
        if ($error !== null) {
            WP_CLI::error($error);
        }
        WP_CLI::success('A zip file named ' . $output . ' has been created');
    }

    /**
     * Exports selected site tables without global users or network tables.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : Output SQL filename.
     * [--tables=<tables>]
     * : Explicit selection of site-owned tables.
     * [--custom-tables=<tables>]
     * : Additional tables; does not replace the default core tables.
     */
    public function tables($args = [], $assoc_args = [], $verbose = true)
    {
        $file = null;
        $reserved = false;
        try {
            $tables = $this->select_tables($assoc_args);
            $file = $this->output_path($args[0] ?? 'rrze-migration-tables.sql');
            fclose(Files::output($file));
            $reserved = true;
            $this->write_tables($file, $tables);
        } catch (\Throwable $error) {
            if ($reserved) {
                unlink($file);
            }
            WP_CLI::error($error->getMessage());
        }
        $this->success('Site tables exported.', $verbose);
    }

    /**
     * Exports site members without passwords, sessions or network permissions.
     *
     * ## OPTIONS
     *
     * [<outputfile>]
     * : Output CSV filename.
     * [--usersuffix=<suffix>]
     * : Unsupported; SSO logins must not be changed.
     * [--woocomerce]
     * : Unsupported legacy flag; only actual site members are exported.
     */
    public function users($args = [], $assoc_args = [], $verbose = true)
    {
        $file = null;
        $reserved = false;
        try {
            $this->validate_options($assoc_args);
            $file = $this->output_path($args[0] ?? 'rrze-migration-users.csv');
            fclose(Files::output($file));
            $reserved = true;
            $this->write_users($file);
        } catch (\Throwable $error) {
            if ($reserved) {
                unlink($file);
            }
            WP_CLI::error($error->getMessage());
        }
        $this->success('Site users exported without credentials.', $verbose);
    }

    private function validate_options(array $options): void
    {
        if (!empty($options['usersuffix'])) {
            throw new RuntimeException('SSO user_login values must not be changed; --usersuffix is unsupported.');
        }
        if (isset($options['plugins']) || isset($options['themes'])) {
            throw new RuntimeException('Provide plugins and themes separately in the destination; executable code is not part of a migration package.');
        }
        if (isset($options['woocomerce'])) {
            throw new RuntimeException('The legacy --woocomerce export is unsupported; export actual site members.');
        }
    }

    private function select_tables(array $options): array
    {
        global $wpdb;
        $available = $wpdb->get_col('SHOW TABLES');
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not list source tables.');
        }
        return Tables::select($available, array_values($wpdb->tables('blog')), array_values($wpdb->tables('global')), $wpdb->prefix, $wpdb->base_prefix, $options['tables'] ?? '', $options['custom-tables'] ?? '');
    }

    private function write_tables(string $filename, array $tables): void
    {
        // An explicit value prevents WP-CLI from rewriting --no-tablespaces to --tablespaces=false.
        Utils::checked_command('db export', [$filename], ['tables' => implode(',', $tables), 'skip-triggers' => true, 'no-tablespaces' => '1']);
        if (!is_file($filename) || filesize($filename) === 0) {
            throw new RuntimeException('The SQL export is empty.');
        }
    }

    private function write_users(string $filename): void
    {
        $headers = self::getUserCSVHeaders();
        $handle = @fopen($filename, 'wb');
        if (!$handle) {
            throw new RuntimeException('Cannot open the user export file.');
        }
        chmod($filename, 0600);
        try {
            if (fputcsv($handle, $headers, ',', '"', '\\') === false) {
                throw new RuntimeException('Could not write user CSV headers.');
            }
            foreach (get_users(['blog_id' => get_current_blog_id()]) as $user) {
                $row = [];
                foreach (Users::HEADERS as $field) {
                    $row[$field] = $field === 'role' ? ($user->roles[0] ?? '') : $user->get($field);
                }
                // Filters may supply custom fields, but cannot rename identities or add credentials.
                $custom = apply_filters('rrze_migration_export_user_data', [], $user);
                foreach (array_diff($headers, Users::HEADERS) as $field) {
                    $row[$field] = $custom[$field] ?? $user->get($field);
                }
                $values = [];
                foreach ($headers as $field) {
                    $value = $row[$field] ?? '';
                    $values[] = is_scalar($value) || $value === null ? (string) $value : serialize($value);
                }
                if (fputcsv($handle, $values, ',', '"', '\\') === false) {
                    throw new RuntimeException('Could not completely write a user CSV row.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public static function getUserCSVHeaders()
    {
        return Users::headers(apply_filters('rrze_migration_export_user_headers', []));
    }

    private function output_path(string $filename): string
    {
        if ($filename === '' || str_contains($filename, '://')) {
            throw new RuntimeException('Provide a local output filename.');
        }
        return str_starts_with($filename, '/') ? $filename : ABSPATH . $filename;
    }
}
