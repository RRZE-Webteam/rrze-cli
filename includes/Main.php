<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI;

class Main
{
    /**
     * __construct
     */
    public function __construct()
    {
        // Check if WP_CLI is available
        if (!class_exists('WP_CLI')) {
            return;
        }

        if (!is_multisite()) {
            WP_CLI::error(__('This command must be run on a multisite installation.', 'rrze-cli'));
        }

        // Get the current working directory
        $current_dir = getcwd();

        // Output the current directory
        WP_CLI::log("Current directory: " . $current_dir);

        // Check if the command is running in the main directory of WordPress
        if (!file_exists($current_dir . '/wp-load.php')) {
            WP_CLI::error(__('This command must be run from the main directory of a WordPress installation.', 'rrze-cli'));
        }

        // rrze-cli
        WP_CLI::add_command('rrze-cli info', __NAMESPACE__ . '\\Info');
        // rrze-migration
        WP_CLI::add_command('rrze-migration export', __NAMESPACE__ . '\\Migration\\Export');
        WP_CLI::add_command('rrze-migration import', __NAMESPACE__ . '\\Migration\\Import');
        WP_CLI::add_command('rrze-migration posts', __NAMESPACE__ . '\\Migration\\Posts');
        // rrze-cli
        WP_CLI::add_command('rrze-multilang postmeta', __NAMESPACE__ . '\\Multilang\\PostMeta');        
    }
}
