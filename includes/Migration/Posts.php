<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Manage posts.
 *
 * ## EXAMPLES
 *
 * Updates all post_author values on the website with ID=2 based on the map_users.json file.
 * wp rrze-migration posts update_author map_users.json --blog_id=2
 * 
 * @package RRZE\CLI
 */
class Posts extends Command
{
    /**
     * Updates all post_author values in all wp_posts records that have post_author != 0.
     *
     * It uses a user mapping file, which contains the new user ID for each old user ID. This user mapping file should be passed to the
     * command as an argument.
     *
     * ## OPTIONS
     * 
     * <inputfile>
     * : The name of the user mapping file in JSON format.
     * 
     * --blog_id=<blog_id>
     * : The ID of the website.
     * 
     * [--uid_fields=<uid_fields>]
     * : User meta field.
     * 
     * ## EXAMPLES
     * 
     * Updates all post_author values on the website with ID=2 based on the users_map.json file and the user meta field _user_meta_field.
     * wp rrze-migration posts update_author users_map.json --blog_id=2 --uid_fields=_user_meta_field
     *
     * @param array $args
     * @param array $assoc_args
     * @param bool  $verbose
     */
    public function update_author($args = [], $assoc_args = [], $verbose = true)
    {
        global $wpdb;

        $this->process_args(
            [
                0 => '', // .json map file
            ],
            $args,
            [
                'blog_id'    => '',
                'uid_fields' => '',
            ],
            $assoc_args
        );

        $filename = $this->args[0];

        $is_multisite = is_multisite();

        if (empty($filename) || !file_exists($filename)) {
            WP_CLI::warning(__('Invalid input file', 'rrze-cli'));
            return;
        }

        if ($is_multisite) {
            switch_to_blog((int) $this->assoc_args['blog_id']);
        }

        $is_woocommerce = Utils::is_woocommerce_active();

        $ids_map = json_decode(file_get_contents($filename));

        if (null === $ids_map) {
            WP_CLI::warning(
                __('An error has occurred when parsing the json file', 'rrze-cli')
            );
        }

        $equals_id = [];
        $author_not_found = [];

        $this->all_records(
            __('Updating posts authors', 'rrze-cli'),
            $wpdb->posts,
            function ($result) use (&$equals_id, &$author_not_found, $ids_map, $verbose, $is_woocommerce) {
                $author = $result->post_author;

                if (isset($ids_map->{$author})) {
                    if ($author != $ids_map->{$author}) {
                        global $wpdb;

                        $wpdb->update(
                            $wpdb->posts,
                            ['post_author' => $ids_map->{$author}],
                            ['ID' => $result->ID],
                            ['%d'],
                            ['%d']
                        );

                        $this->log(sprintf(
                            /* translators: %1$s: post title, %2$d: post ID */
                            __('Updated post_author for "%1$s" (ID #%2$d)', 'rrze-cli'),
                            $result->post_title,
                            absint($result->ID)
                        ), $verbose);
                    } else {
                        $this->log(sprintf(
                            /* translators: %d: post ID */
                            __('#%d New user ID equals to the old user ID', 'rrze-cli'),
                            $result->ID
                        ), $verbose);
                        $equals_id[] = absint($result->ID);
                    }
                } else {
                    $this->log(sprintf(
                        /* translators: %d: post ID */
                        __("#%d New user ID not found or it is already been updated", 'rrze-cli'),
                        absint($result->ID)
                    ), $verbose);

                    $author_not_found[] = absint($result->ID);
                }

                // Parse uid_fields.
                $uid_fields = explode(',', $this->assoc_args['uid_fields']);
                // Automatically add Woocommerce user id field
                if ($is_woocommerce) {
                    $uid_fields[] = '_customer_user';
                }
                // Iterate over fields and update them.
                foreach (array_filter($uid_fields) as $f) {
                    $f = trim($f);
                    $old_user = get_post_meta((int) $result->ID, $f, true);

                    if (isset($ids_map->{$old_user}) && $old_user != $ids_map->{$old_user}) {
                        $new_user = $ids_map->{$old_user};

                        update_post_meta((int) $result->ID, $f, $new_user);

                        $this->log(sprintf(
                            /* translators: %1$s: field name, %2$s: post title, %3$d: post ID */
                            __('Updated %1$s for "%2$s" (ID #%d)', 'rrze-cli'),
                            $f,
                            $result->post_title,
                            absint($result->ID)
                        ), $verbose);
                    }
                }
            }
        );

        if (!empty($author_not_found)) {
            $this->warning(sprintf(
                /* translators: %1$d: number of records, %2$s: list of IDs */
                __('%1$d records failed to update its post_author: %2$s', 'rrze-cli'),
                count($author_not_found),
                implode(',', $author_not_found)
            ), $verbose);
        }

        if (!empty($equals_id)) {
            $this->warning(sprintf(
                /* translators: %s: list of IDs */
                __('The following records have the new ID equal to the old ID: %s', 'rrze-cli'),
                implode(',', $equals_id)
            ), $verbose);
        }

        if ($is_multisite) {
            restore_current_blog();
        }
    }
}
