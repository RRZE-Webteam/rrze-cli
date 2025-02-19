<?php

namespace RRZE\CLI\Multilang;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Class Migration
 * Migrate multilingual post meta data from workflow plugin to multilang plugin.
 * 
 * $ wp rrze-multilang migration workflow <main|secondary> [--force] --meta_key=<meta_key> --url=<url>
 * <main|secondary> : The main or secondary blog ID.
 * --force : Forces rewriting of metadata.
 * --meta_key : The meta key to migrate.
 * --url : The URL of the website.
 * Examples:
 * wp rrze-multilang migration workflow main --meta_key=_version_remote_parent_post_meta --url=www.site.de.localhost
 * wp rrze-multilang migration workflow secondary --meta_key=_version_remote_post_meta --url=www.site.eu.localhost
 * 
 * @package RRZE\CLI\Multilang
 */
class Migration extends Command
{
    /**
     * @var bool
     */
    private $force = false;

    /**
     * Migrate multilingual post meta data from workflow plugin to multilang plugin.
     * @param array $args
     * @param array $assocArgs
     * @param bool $verbose
     * @return void
     */
    public function workflow($args = [], $assocArgs = [], $verbose = true)
    {
        // Check if the command is running in the main directory of WordPress.
        if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-load.php')) {
            WP_CLI::error(__('This command must be run from the main directory of a WordPress installation.', 'rrze-cli'));
        }

        // Check if the argument force is set.
        if (isset($assocArgs['force'])) {
            $this->force = true;
        }

        $this->process_args(
            [],
            $args,
            [
                'meta_key' => ''
            ],
            $assocArgs
        );

        // error_log(print_r($this->args, true));
        // error_log(print_r($this->assoc_args, true));

        // error_log('current blog id: ' . get_current_blog_id());

        $blogId = get_current_blog_id();
        $metaKey = $this->assoc_args['meta_key'];

        // Check if the blog ID exists and is public
        $blogDetails = get_blog_details($blogId);
        if (!$blogDetails || !$blogDetails->public) {
            WP_CLI::error(__('Invalid or non-public blog ID', 'rrze-cli'));
        }

        WP_CLI::log("Website: " . $blogDetails->siteurl);

        $this->start($blogId, $metaKey);
    }

    private function start($blogId, $metaKey)
    {
        switch_to_blog($blogId);

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

        // Array to store post IDs and their corresponding meta values.
        $postsWithMeta = [];

        // Initialize a variable to store the success status of the migration.
        $success = false;

        foreach ($postIds as $postId) {
            // Retrieve the meta value for the current post ID.
            $metaValue = get_post_meta($postId, $metaKey, true);
            $blogIdReference = $metaValue['blog_id'] ?? '';
            $postIdReference = $metaValue['post_id'] ?? '';
            if (!$blogIdReference || !$postIdReference) {
                continue;
            }

            $postsWithMeta[$postId] = $metaValue; // Map post ID to meta value.

            if (metadata_exists('post', $postId, '_rrze_multilang_multiple_reference') && !$this->force) {
                continue;
            }

            $multilangMeta = [
                $blogIdReference => $postIdReference
            ];

            delete_post_meta($postId, '_rrze_multilang_multiple_reference'); // remove all metadata matching the key.
            $success = add_post_meta($postId, '_rrze_multilang_multiple_reference', $multilangMeta); // add metadata to the post.
        }

        restore_current_blog();

        // // Log or return the results.
        // error_log(print_r($postsWithMeta, true));

        if ($success) {
            WP_CLI::success('Migration completed successfully.');
        } else {
            WP_CLI::log('The migration did not take place. One or more Multisite metadata already existed.');
        }
    }
}
