<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RRZE\CLI\{Command, Utils};
use WP_CLI;

/**
 * Manage posts (author remapping and related meta updates).
 *
 * ## EXAMPLES
 *
 * Update all post_author values on the website with ID=2 based on the users_map.json file.
 * wp rrze-migration posts update_author users_map.json --blog_id=2
 *
 * @package RRZE\CLI
 */
class Posts extends Command
{
    /**
     * Update post authors using a JSON user ID mapping file.
     *
     * The mapping file must be a JSON object (or associative array when decoded) of the form:
     * {
     *   "OLD_USER_ID": NEW_USER_ID,
     *   "12": 345,
     *   ...
     * }
     *
     * This command:
     *  - Updates wp_posts.post_author for posts where post_author != 0 and a mapping exists.
     *  - Optionally updates per-post meta fields listed in --uid_fields (comma-separated).
     *  - Automatically includes the WooCommerce field "_customer_user" if WooCommerce is active.
     *
     * ## OPTIONS
     *
     * <inputfile>
     * : Path to the user mapping JSON file.
     *
     * --blog_id=<blog_id>
     * : Target site ID (required on multisite).
     *
     * [--uid_fields=<uid_fields>]
     * : Comma-separated list of post meta keys that store user IDs and should be remapped.
     *
     * ## EXAMPLES
     *
     * Update authors and a custom user field "_assignee_user_id":
     * wp rrze-migration posts update_author users_map.json --blog_id=2 --uid_fields=_assignee_user_id
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
                0 => '', // mapping .json
            ],
            $args,
            [
                'blog_id'    => '',
                'uid_fields' => '',
            ],
            $assoc_args
        );

        $filename     = $this->args[0] ?? '';
        $is_multisite = is_multisite();

        if ($filename === '' || !is_file($filename) || !is_readable($filename)) {
            WP_CLI::warning(__('Invalid input file', 'rrze-cli'));
            return;
        }

        if ($is_multisite && (string) $this->assoc_args['blog_id'] === '') {
            WP_CLI::warning(__('--blog_id is required on multisite', 'rrze-cli'));
            return;
        }

        // Decode mapping as an associative array to safely use numeric keys.
        $ids_map = json_decode((string) file_get_contents($filename), true);
        if (!is_array($ids_map)) {
            WP_CLI::warning(__('An error has occurred when parsing the json file (not an object/array).', 'rrze-cli'));
            return;
        }

        // Normalize mapping keys and values to integers where possible.
        $normalized_map = [];
        foreach ($ids_map as $old => $new) {
            // Skip invalid pairs
            if ($old === '' || $new === '' || !is_numeric($old) || !is_numeric($new)) {
                continue;
            }
            $normalized_map[(string) (int) $old] = (int) $new;
        }
        unset($ids_map);

        if ($normalized_map === []) {
            WP_CLI::warning(__('Mapping file is empty or contains no valid numeric ID pairs.', 'rrze-cli'));
            return;
        }

        // Prepare list of per-post meta uid fields to update.
        $uid_fields = array_filter(array_map('trim', explode(',', (string) $this->assoc_args['uid_fields'])));
        $uid_fields = array_values(array_unique($uid_fields));

        // Auto-include WooCommerce field when WC is active.
        if (Utils::is_woocommerce_active() && !in_array('_customer_user', $uid_fields, true)) {
            $uid_fields[] = '_customer_user';
        }

        // Switch blog if needed.
        if ($is_multisite) {
            Utils::maybe_switch_to_blog((int) $this->assoc_args['blog_id']);
        }

        $equals_id         = []; // post IDs where new == old
        $author_not_found  = []; // post IDs where mapping not found for existing author
        $count_author_upd  = 0;  // number of posts with author updated
        $count_meta_upd    = 0;  // number of meta updates performed (sum across fields)

        // Iterate over all records in posts table
        $this->all_records(
            __('Updating posts authors', 'rrze-cli'),
            $wpdb->posts,
            function ($result) use (&$equals_id, &$author_not_found, &$count_author_upd, &$count_meta_upd, $normalized_map, $uid_fields, $verbose) {
                global $wpdb;

                $post_id   = (int) $result->ID;
                $post_title = (string) $result->post_title;
                $author    = (int) $result->post_author;

                // Only consider posts with a non-zero author
                if ($author === 0) {
                    return;
                }

                $author_key = (string) $author;

                if (array_key_exists($author_key, $normalized_map)) {
                    $new_author = (int) $normalized_map[$author_key];

                    if ($new_author !== $author) {
                        $wpdb->update(
                            $wpdb->posts,
                            ['post_author' => $new_author],
                            ['ID' => $post_id],
                            ['%d'],
                            ['%d']
                        );

                        $this->log(sprintf(
                            /* translators: %1$s: post title, %2$d: post ID, %3$d: old author, %4$d: new author */
                            __('Updated post_author for "%1$s" (ID #%2$d): %3$d -> %4$d', 'rrze-cli'),
                            $post_title,
                            $post_id,
                            $author,
                            $new_author
                        ), $verbose);

                        $count_author_upd++;
                    } else {
                        $this->log(sprintf(
                            /* translators: %1$d: post ID, %2$d: author ID */
                            __('Post #%1$d skipped: new author ID equals old author ID (%2$d).', 'rrze-cli'),
                            $post_id,
                            $author
                        ), $verbose);
                        $equals_id[] = $post_id;
                    }
                } else {
                    $this->log(sprintf(
                        /* translators: %1$d: post ID, %2$d: author ID */
                        __('Post #%1$d skipped: no mapping for current author ID (%2$d) or already remapped.', 'rrze-cli'),
                        $post_id,
                        $author
                    ), $verbose);
                    $author_not_found[] = $post_id;
                }

                // Update any requested per-post meta fields that hold user IDs.
                if (!empty($uid_fields)) {
                    foreach ($uid_fields as $field) {
                        if ($field === '') {
                            continue;
                        }
                        $old_user = get_post_meta($post_id, $field, true);

                        // Only attempt remap if the meta is present and numeric
                        if ($old_user === '' || !is_numeric($old_user)) {
                            continue;
                        }

                        $key = (string) (int) $old_user;
                        if (array_key_exists($key, $normalized_map)) {
                            $new_user = (int) $normalized_map[$key];

                            if ($new_user !== (int) $old_user) {
                                update_post_meta($post_id, $field, $new_user);

                                $this->log(sprintf(
                                    /* translators: %1$s: field name, %2$s: post title, %3$d: post ID, %4$d: old user, %5$d: new user */
                                    __('Updated %1$s for "%2$s" (ID #%3$d): %4$d -> %5$d', 'rrze-cli'),
                                    $field,
                                    $post_title,
                                    $post_id,
                                    (int) $old_user,
                                    $new_user
                                ), $verbose);

                                $count_meta_upd++;
                            }
                        }
                    }
                }
            }
        );

        // Summaries and warnings
        if (!empty($author_not_found)) {
            $this->warning(sprintf(
                /* translators: %1$d: number of records, %2$s: list of IDs */
                __('%1$d records skipped (no author mapping found): %2$s', 'rrze-cli'),
                count($author_not_found),
                implode(',', $author_not_found)
            ), $verbose);
        }

        if (!empty($equals_id)) {
            $this->warning(sprintf(
                /* translators: %s: list of IDs */
                __('The following records have identical new/old author IDs and were skipped: %s', 'rrze-cli'),
                implode(',', $equals_id)
            ), $verbose);
        }

        $this->success(sprintf(
            /* translators: %1$d: authors updated, %2$d: meta fields updated */
            __('Done. Authors updated: %1$d | Meta fields updated: %2$d', 'rrze-cli'),
            $count_author_upd,
            $count_meta_upd
        ), $verbose);

        if ($is_multisite) {
            Utils::maybe_restore_current_blog();
        }
    }
}
