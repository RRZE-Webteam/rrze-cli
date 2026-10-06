<?php

namespace RRZE\CLI\Migration;

defined('ABSPATH') || exit;

use RuntimeException;

/** Internal reference updates, called only with the site just created by Import. */
final class Posts
{
    public static function remap(int $blogId, array $ids, array $fields): void
    {
        global $wpdb;
        switch_to_blog($blogId);
        try {
            foreach ([[$wpdb->posts, 'ID', 'post_author'], [$wpdb->comments, 'comment_ID', 'user_id']] as [$table, $key, $column]) {
                $last = 0;
                do {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT `$key` AS record_id, `$column` AS user_id FROM `$table` WHERE `$key` > %d ORDER BY `$key` LIMIT 500", $last));
                    if ($wpdb->last_error) {
                        throw new RuntimeException('Could not read user references from the new site.');
                    }
                    foreach ($rows as $row) {
                        $last = (int) $row->record_id;
                        $old = (int) $row->user_id;
                        if ($old === 0) {
                            continue;
                        }
                        if (!isset($ids[$old])) {
                            throw new RuntimeException('The package is missing a user referenced by a post or comment.');
                        }
                        // Update each record once, even when source and target ID ranges overlap.
                        if ($wpdb->update($table, [$column => $ids[$old]], [$key => $last], ['%d'], ['%d']) === false) {
                            throw new RuntimeException('Could not update a user reference in the new site.');
                        }
                    }
                } while (count($rows) === 500);
            }
            foreach ($fields as $field) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $field));
                if ($wpdb->last_error) {
                    throw new RuntimeException('Could not read numeric user metadata.');
                }
                foreach ($rows as $row) {
                    if ($row->meta_value === '' || $row->meta_value === '0') {
                        continue;
                    }
                    if (!ctype_digit($row->meta_value) || !isset($ids[(int) $row->meta_value])) {
                        throw new RuntimeException('A numeric user metadata field has no valid identity mapping.');
                    }
                    if ($wpdb->update($wpdb->postmeta, ['meta_value' => $ids[(int) $row->meta_value]], ['meta_id' => $row->meta_id]) === false) {
                        throw new RuntimeException('Could not update numeric user metadata.');
                    }
                }
            }
        } finally {
            restore_current_blog();
        }
    }
}
