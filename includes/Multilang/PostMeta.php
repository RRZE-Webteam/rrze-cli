<?php

namespace RRZE\CLI\Multilang;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

class PostMeta extends Command
{
    // $ wp rrze-multilang postmeta site <blog_id> --meta_key=<meta_key>
    public function site($args = [], $assoc_args = [], $verbose = true)
    {
        global $wpdb;

        $is_multisite = is_multisite();

        $this->process_args(
            [
                0 => 0
            ],
            $args,
            [
                'meta_key'  => ''
            ],
            $assoc_args
        );


        $blog_id = absint($this->args[0]);

        // Check if the blog ID exists and is public
        $blog_details = get_blog_details($blog_id);
        if (!$blog_details || !$blog_details->public) {
            WP_CLI::error(__('Invalid or non-public blog ID', 'rrze-cli'));
        }

        switch_to_blog($blog_id);

        // Get all published post IDs.
        $args = array(
            "post_type" => "any",  // Loop through all post types.
            "post_status" => "publish",
            "fields" => "ids",  // Only return IDs.
            "posts_per_page" => -1,  // Get all posts.
            [
                "meta_query" => [
                    "relation" => "AND",
                    [
                        "key" => "_version_remote_parent_post_meta", // Check if the meta key exists (main website).
                        "compare" => "EXISTS",
                    ],
                    [
                        "key" => "_version_remote_parent_post_meta",
                        "value" => "",
                        "compare" => "!=",
                    ],
                ],
            ]
        );
        $published_posts = get_posts($args);

        restore_current_blog();

        // error_log(print_r($published_posts, true));
    }
}
