<?php

namespace RRZE\CLI\Multilang;

defined('ABSPATH') || exit;

use RRZE\CLI\Command;
use WP_CLI;

/**
 * Class Migration
 * Migrate multilingual post meta data from workflow plugin to multilang plugin.
 * 
 * @package RRZE\CLI\Multilang
 */
class Migration extends Command
{
    /** 
     * Is the main website 
     * @var bool
     */
    private $isMain = false;

    /**
     * Force rewriting of metadata.
     * @var bool
     */
    private $force = false;

    /**
     * Verbose output.
     * @var bool
     */
    private $verbose = false;

    /**
     * Migrate multilingual post meta data from workflow plugin to multilang plugin.
     * 
     * ## OPTIONS
     * <main|secondary>
     * : The main or secondary website.
     * [--force]
     * : Forces rewriting of metadata.
     * --meta_key=<meta_key>
     * : The meta key to migrate.
     * --url=<url>
     * : The URL of the website.
     * 
     * ## EXAMPLES
     * wp rrze-multilang migration workflow main --meta_key=_version_remote_parent_post_meta --url=www.site.de
     * wp rrze-multilang migration workflow secondary --meta_key=_version_remote_post_meta --url=www.site.eu
     * 
     * @param array $args
     * @param array $assocArgs
     * @param bool $verbose
     * @return void
     */
    public function workflow($args = [], $assocArgs = [])
    {
        // Check if the command is running in the main directory of WordPress.
        if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-load.php')) {
            WP_CLI::error(__('This command must be run from the main directory of a WordPress installation.', 'rrze-cli'));
        }

        if (!is_multisite()) {
            WP_CLI::error(__('This command is only available for multisite installations.', 'rrze-cli'));
        }

        $this->process_args(
            [],
            $args,
            [
                'meta_key' => ''
            ],
            $assocArgs
        );

        // Check if the argument main is set.
        if (in_array('main', $this->args, true)) {
            $this->isMain = true;
        }

        // Check if the associated argument force is set.
        if (isset($this->assoc_args['force'])) {
            $this->force = true;
        }

        // Check if the associated argument verbose is set.
        if (isset($this->assoc_args['verbose'])) {
            $this->verbose = true;
        }

        $metaKey = $this->assoc_args['meta_key'];

        // Check if the blog ID exists and is public
        $blogDetails = get_blog_details();
        if (!$blogDetails || !$blogDetails->public) {
            WP_CLI::error(__('Invalid or non-public blog ID', 'rrze-cli'));
        }

        WP_CLI::log("Website: " . $blogDetails->siteurl);

        $this->start($metaKey);
    }

    /**
     * Start the migration process.
     * @param string $metaKey
     * @return void
     */
    private function start($metaKey)
    {
        // Check if the meta key is provided
        if (empty($metaKey)) {
            WP_CLI::error(__('The meta key is required.', 'rrze-cli'));
        }

        // Get all published post IDs with the specified meta_key.
        $args = [
            "post_type" => "any",  // Loop through all post types.
            "post_status" => "publish",
            "fields" => "ids",  // Only return IDs.
            "posts_per_page" => -1,  // Get all posts.
            "meta_query" => [
                "relation" => "AND",
                [
                    "key" => $metaKey, // Check if the meta key exists.
                    "compare" => "EXISTS",
                ],
                [
                    "key" => $metaKey, // Check if the meta key is not empty.
                    "value" => "",
                    "compare" => "!=",
                ],
            ],
        ];

        $postIds = get_posts($args);
        if (empty($postIds)) {
            WP_CLI::error(__('The migration did not take place. No posts found with the specified Workflow meta key.', 'rrze-cli'));
        }

        // Initialize a variable to store the success status of the migration.
        $success = false;

        global $wpdb;

        foreach ($postIds as $postId) {
            if ($this->isMain) {
                // Get the meta value for the main website.
                $metaEntry = get_post_meta($postId, $metaKey);
            } else {
                // Query the database to get the most recent record for the given post ID and meta key.
                $metaEntry = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT meta_id, meta_value 
                     FROM {$wpdb->postmeta} 
                     WHERE post_id = %d AND meta_key = %s 
                     ORDER BY meta_id DESC 
                     LIMIT 1",
                        $postId,
                        $metaKey
                    ),
                    ARRAY_A
                );
            }

            if (empty($metaEntry)) {
                continue; // Skip if no meta entry is found.
            }

            // Check if is not main website and if the meta value is serialized.
            if (!$this->isMain) {
                // Extract the meta_value and decode it if it's serialized.
                $metaEntry[] = maybe_unserialize($metaEntry['meta_value']);
            }

            $multilangMeta = []; // Initialize an array to store the multilingual metadata.

            // Loop through the meta value to extract blog ID and post ID references.
            foreach ($metaEntry as $metaValue) {
                if (empty($metaValue)) {
                    continue; // Skip if the meta value is empty.
                }

                // Process the meta_value as needed.
                $blogIdReference = !empty($metaValue['blog_id']) ? absint($metaValue['blog_id']) : 0;
                $postIdReference = !empty($metaValue['post_id']) ? absint($metaValue['post_id']) : 0;

                if (empty($blogIdReference) || empty($postIdReference)) {
                    continue;
                }

                // Check if the blog ID exists and is valid (not archived or deleted).
                $blogDetails = get_blog_details($blogIdReference);

                if (!$blogDetails || $blogDetails->archived || $blogDetails->deleted) {
                    WP_CLI::log(
                        sprintf(
                            /* translators: 1: Blog ID reference */
                            __('Blog ID %s is invalid, archived, or deleted.', 'rrze-cli'),
                            $blogIdReference
                        )
                    );
                    continue; // Skip if the blog ID is invalid, archived, or deleted.
                }

                $hasPostIdReference = false;
                // Check if the post ID reference exists in the specified blog ID.
                switch_to_blog($blogIdReference); // Switch to the blog ID reference.
                global $wpdb;
                $postExists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                        $postIdReference
                    )
                );
                if ($postExists) {
                    $hasPostIdReference = true; // Post ID reference exists.
                }
                restore_current_blog(); // Restore the current blog context.
                if (!$hasPostIdReference) {
                    WP_CLI::log(
                        sprintf(
                            /* translators: 1: Post ID reference, 2: Blog ID reference */
                            __('Post ID reference %1$s does not exist in blog ID reference %2$s', 'rrze-cli'),
                            $postIdReference,
                            $blogIdReference
                        )
                    );
                    continue; // Skip if the post ID reference does not exist.
                }

                $multilangMeta = empty($multilangMeta)
                    ? [$blogIdReference => $postIdReference]
                    : $multilangMeta + [$blogIdReference => $postIdReference];
            }

            if (empty($multilangMeta)) {
                continue; // Skip if no multilingual metadata is found.
            }

            // Check if the metadata already exists for the post ID.
            if (metadata_exists('post', $postId, '_rrze_multilang_multiple_reference') && !$this->force) {
                continue;
            }

            delete_post_meta($postId, '_rrze_multilang_multiple_reference'); // Remove all metadata matching the key.
            $success = add_post_meta($postId, '_rrze_multilang_multiple_reference', $multilangMeta); // Update the metadata for the post.
        }

        if ($success) {
            WP_CLI::success(__('Migration completed successfully.', 'rrze-cli'));
        } else {
            WP_CLI::log(__('The migration did not take place. One or more Multisite metadata already existed.', 'rrze-cli'));
        }
    }
}
