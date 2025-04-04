<?php

namespace RRZE\CLI\Multilang;

defined('ABSPATH') || exit;

use RRZE\CLI\Command;
use WP_CLI;

/**
 * Class Stats
 * Provides statistics on the usage of the workflow plugin's.
 * Optionally deactivates the network and translation modules from the websites that are not linked to another website.
 * 
 * @package RRZE\CLI\Multilang
 */
class Stats extends Command
{
    /**
     * The option name for the network module.
     * @var string
     */
    private $networkOptionName = '_cms_workflow_network_options';

    /**
     * The option name for the translation module.
     * @var string
     */
    private $translationOptionName = '_cms_workflow_translation_options';

    /**
     * Indicates whether to deactivate the network and translation modules.
     * @var bool
     */
    private $deactivate = false;

    /**
     * Verbose output.
     * @var bool
     */
    private $verbose = false;

    /**
     * Provides statistics on the usage of the workflow plugin's network module.
     * Optionally deactivates the network and translation modules from the websites that are not linked to another website.
     * 
     * ## OPTIONS
     * [--deactivate]
     * : Deactivate the network and translation modules.
     * [--verbose]
     * : Enables verbose output.
     * 
     * ## EXAMPLES
     * wp rrze-multilang stats workflow
     * 
     * @param array $args
     * @param array $assocArgs
     * @return void
     */
    public function workflow($args = [], $assocArgs = [])
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

        // Check if the associated argument deactivate is set.
        if (isset($this->assoc_args['deactivate'])) {
            $this->deactivate = true;
        }

        // Check if the associated argument verbose is set.
        if (isset($this->assoc_args['verbose'])) {
            $this->verbose = true;
        }

        $this->stats();
    }

    /**
     * Get the statistics for the network module.
     * @return void
     */
    private function stats()
    {
        // Get all non-archived and non-deleted websites in the network.
        $sites = get_sites([
            'archived' => 0,
            'deleted' => 0,
            'number' => 10000,
        ]);

        $count = 0;
        $linkedUrls = []; // network module activated and linked to another website.
        $notLinkedUrls = []; // network module activated but no link to another website.

        // Loop through each website.
        foreach ($sites as $site) {
            // Increment the count of websites processed.
            $count++;

            // Get the URL of the website.
            $siteUrl = $site->domain . $site->path;
            if ($this->verbose) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %s: The URL of the website being processed. */
                        __('Processing website: %s', 'rrze-cli'),
                        $siteUrl
                    )
                );
            }

            // Switch to the website.
            switch_to_blog($site->blog_id);

            // Check if the website has the network module option and if the 'activated' property is true.
            $networkOptionValue = get_option($this->networkOptionName);
            if (is_object($networkOptionValue) && isset($networkOptionValue->activated) && $networkOptionValue->activated) {
                // Check if the website has network connections or a parent site.
                if (!empty($networkOptionValue->network_connections) || !empty($networkOptionValue->parent_site)) {
                    // Increment count and save the URL.
                    $linkedUrls[] = $siteUrl;
                } else {
                    if ($this->deactivate) {
                        // Deactivate the network and translation modules.
                        $this->deactivateModules($siteUrl);
                    } else {
                        // Increment count and save the URL.
                        $notLinkedUrls[] = $siteUrl;
                    }
                }
            }

            // Restore the current website.
            restore_current_blog();
        }

        // Log the count of websites processed.
        WP_CLI::log(
            sprintf(
                /* translators: %s: The number of websites processed. */
                __('Total websites processed: %s', 'rrze-cli'),
                $count
            )
        );

        // Log the linked websites URLs.
        if (!empty($linkedUrls)) {
            sort($linkedUrls);
            $linkedUrlsCount = count($linkedUrls);
            WP_CLI::log(
                sprintf(
                    /* translators: 1: Number of linked websites, 2: Total number of websites, 3: New line, 4: Linked websites URLs */
                    __('Linked websites URLs (%1$d/%2$d):%3$s%4$s', 'rrze-cli'),
                    $linkedUrlsCount,
                    $count,
                    PHP_EOL,
                    implode(PHP_EOL, $linkedUrls)
                )
            );
        }

        // Log the not linked websites URLs.
        if (!empty($notLinkedUrls)) {
            sort($notLinkedUrls);
            $notLinkedUrlsCount = count($notLinkedUrls);
            WP_CLI::log(
                sprintf(
                    /* translators: 1: Number of not linked websites, 2: Total number of websites, 3: New line, 4: Not linked websites URLs */
                    __('Not linked websites URLs (%1$d/%2$d):%3$s%4$s', 'rrze-cli'),
                    $notLinkedUrlsCount,
                    $count,
                    PHP_EOL,
                    implode(PHP_EOL, $notLinkedUrls)
                )
            );
        }
    }

    /**
     * Deactivate the network and translation modules for the specified website.
     * 
     * @param string $siteUrl The URL of the website.
     * @return void
     */
    private function deactivateModules($siteUrl)
    {
        // Deactivate the network module.
        $networkOptionValue = get_option($this->networkOptionName);
        if (is_object($networkOptionValue) && isset($networkOptionValue->activated) && $networkOptionValue->activated) {
            if ($this->verbose) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %s: The URL of the website being processed. */
                        __('Deactivating network module for website: %s', 'rrze-cli'),
                        $siteUrl
                    )
                );
            }
            $networkOptionValue->activated = false;
            $updateNetworkOptionName = update_option($this->networkOptionName, $networkOptionValue);
            if ($updateNetworkOptionName && $this->verbose) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %s: The URL of the website being processed. */
                        __('Network module deactivated for website: %s', 'rrze-cli'),
                        $siteUrl
                    )
                );
            }
        }

        // Deactivate the translation module.
        $translationOptionValue = get_option($this->translationOptionName);
        if (is_object($translationOptionValue) && isset($translationOptionValue->activated) && $translationOptionValue->activated) {
            if ($this->verbose) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %s: The URL of the website being processed. */
                        __('Deactivating translation module for website: %s', 'rrze-cli'),
                        $siteUrl
                    )
                );
            }
            $translationOptionValue->activated = false;
            $updateTranslationOptionName = update_option($this->translationOptionName, $translationOptionValue);
            if ($updateTranslationOptionName && $this->verbose) {
                WP_CLI::log(
                    sprintf(
                        /* translators: %s: The URL of the website being processed. */
                        __('Translation module deactivated for website: %s', 'rrze-cli'),
                        $siteUrl
                    )
                );
            }
        }
    }
}
