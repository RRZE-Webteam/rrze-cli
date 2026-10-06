<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI;

/**
 * Class Main
 * Main class for the RRZE-CLI plugin.
 *
 * @package RRZE\CLI
 */
class Main
{
    /**
     * @return void
     * @throws WP_CLI\ExitException
     */
    public function loaded(): void
    {
        // Check if WP_CLI is available
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        // Keep standard output available for machine-readable command results.
        $currentDir = getcwd();
        if (false === $currentDir) {
            WP_CLI::error(__('Unable to determine the current working directory.', 'rrze-cli'));
        }

        WP_CLI::debug('Current directory: ' . $currentDir, 'rrze-cli');

        // Check if the command is running in the main directory of WordPress
        if (!file_exists($currentDir . '/wp-load.php')) {
            WP_CLI::error(__('This command must be run from the main directory of a WordPress installation.', 'rrze-cli'));
        }

        // rrze-cli (base commands)
        WP_CLI::add_command('rrze-cli info', __NAMESPACE__ . '\\Info');

        // rrze-migration (website migration)
        WP_CLI::add_command('rrze-migration export', __NAMESPACE__ . '\\Migration\\Export');
        WP_CLI::add_command('rrze-migration import', __NAMESPACE__ . '\\Migration\\Import');
        WP_CLI::add_command('rrze-migration status', __NAMESPACE__ . '\\Migration\\Status');
        WP_CLI::add_command('rrze-migration wizard', __NAMESPACE__ . '\\Migration\\Wizard');
        // Author/reference updates are internal to the import of a newly created site.

        // rrze-multilang (usage stats & migration of workflow plugin network & translation modules to multilang plugin)
        WP_CLI::add_command('rrze-multilang migration', __NAMESPACE__ . '\\Multilang\\Migration');
        WP_CLI::add_command('rrze-multilang stats', __NAMESPACE__ . '\\Multilang\\Stats');
    }
}
