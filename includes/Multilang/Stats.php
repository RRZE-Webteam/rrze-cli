<?php

namespace RRZE\CLI\Multilang;

defined('ABSPATH') || exit;

use RRZE\CLI\Command;
use WP_CLI;

/**
 * Class Stats
 * Provides statistics on the usage of the workflow plugin's.
 * 
 * @package RRZE\CLI\Multilang
 */
class Stats extends Command
{
    /**
     * The option name for the network module.
     * @var string
     */
    private $optionName = '_cms_workflow_network_options';

    /**
     * Provides statistics on the usage of the workflow plugin's network module.
     * 
     * ## EXAMPLES
     * wp rrze-multilang stats workflow
     * 
     * @param array $args
     * @param array $assocArgs
     * @param bool $verbose
     * @return void
     */
    public function workflow($args = [], $assocArgs = [], $verbose = false)
    {
        // Check if the command is running in the main directory of WordPress.
        if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-load.php')) {
            WP_CLI::error(__('This command must be run from the main directory of a WordPress installation.', 'rrze-cli'));
        }

        $this->process_args(
            [],
            $args,
            $assocArgs
        );

        $this->stats($verbose);
    }

    /**
     * Get the statistics for the network module.
     * @param bool $verbose
     * @return void
     */
    private function stats($verbose)
    {
        // Get all non-archived and non-deleted sites in the network
        $sites = get_sites([
            'archived' => 0,
            'deleted' => 0,
        ]);

        $count = 0;
        $linkedUrls = []; // network module activated and linked to another website
        $notLinkedUrls = []; // network module activated but no link to another website

        // Loop through each site
        foreach ($sites as $site) {
            // Switch to the site
            switch_to_blog($site->blog_id);

            // Check if the site has the specific option and if the 'activated' property is true
            $optionValue = get_option($this->optionName);
            if (is_object($optionValue) && isset($optionValue->activated) && $optionValue->activated) {
                // Perform actions on the site
                $siteUrl = get_site_url();
                if ($verbose) {
                    WP_CLI::log('Processing site: ' . $siteUrl);
                }

                if (!empty($optionValue->network_connections) || !empty($optionValue->parent_site)) {
                    // Increment count and save the URL
                    $count++;
                    $linkedUrls[] = $siteUrl;
                } else {
                    // Increment count and save the URL
                    $count++;
                    $notLinkedUrls[] = $siteUrl;
                }
            }

            // Restore the current site
            restore_current_blog();
        }

        // Log the count of sites processed
        WP_CLI::log("Total sites processed: $count");

        // Log the linked sites URLs
        if (!empty($linkedUrls)) {
            sort($linkedUrls);
            WP_CLI::log("Linked sites URLs: " . implode(PHP_EOL, $linkedUrls));
        }

        // Log the not linked sites URLs
        if (!empty($notLinkedUrls)) {
            sort($notLinkedUrls);
            WP_CLI::log("Not linked sites URLs: " . implode(PHP_EOL, $notLinkedUrls));
        }
    }
}
