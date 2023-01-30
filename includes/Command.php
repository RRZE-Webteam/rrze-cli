<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI, WP_CLI_Command;

/**
 * Command Base Class
 * @package RRZE\CLI
 */
abstract class Command extends WP_CLI_Command
{
    /**
     * Holds the command arguments.
     *
     * @var array
     */
    protected $args;

    /**
     * Holds the command assoc arguments.
     *
     * @var array
     */
    protected $assoc_args;

    /**
     * Processes the provided arguments.
     *
     * @param array $default_args
     * @param array $args
     * @param array $default_assoc_args
     * @param array $assoc_args
     */
    protected function process_args($default_args = [], $args = [], $default_assoc_args = [], $assoc_args = [])
    {
        $this->args = $args + $default_args;
        $this->assoc_args = wp_parse_args($assoc_args, $default_assoc_args);
    }

    /**
     * Runs through all posts and executes the provided callback for each post.
     *
     * @param array    $query_args
     * @param callable $callback
     * @param bool     $verbose
     */
    protected function all_posts($query_args, $callback, $verbose = true)
    {
        if (!is_callable($callback)) {
            WP_CLI::error(__("The provided callback is invalid", 'rrze-cli'));
        }

        $default_args = array(
            'post_type'              => 'post',
            'posts_per_page'         => 1000,
            'post_status'            => array('publish', 'pending', 'draft', 'future', 'private'),
            'cache_results '         => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'offset'                 => 0,
        );

        /**
         * Filters the default args for querying posts in the all_posts method.
         *
         * @param array $default_args
         */
        $default_args = apply_filters('rrze-migration/all_posts/default_args', $default_args);

        $query_args = wp_parse_args($query_args, $default_args);
        $query = new \WP_Query($query_args);

        $counter = 0;
        $found_posts = 0;
        while ($query->have_posts()) {
            $query->the_post();

            $callback();

            if (0 === $counter) {
                $found_posts = $query->found_posts;
            }

            $counter++;

            if (0 === $counter % $query_args['posts_per_page']) {
                Utils::delete_object_cache();
                $this->log(sprintf(__('Posts Updated: %d/%d', 'rrze-cli'), $counter, $found_posts), true);
                $query_args['offset'] += $query_args['posts_per_page'];
                $query = new \WP_Query($query_args);
            }
        }

        wp_reset_postdata();

        $this->success(sprintf(
            __('%d posts were updated', 'rrze-cli'),
            $counter
        ), $verbose);
    }

    /**
     * Runs through all records on a specific table.
     *
     * @param string   $message
     * @param string   $table
     * @param callable $callback
     * @return bool
     */
    protected function all_records($message, $table, $callback)
    {
        global $wpdb;

        $offset = 0;
        $step = 1000;

        $found_posts = $wpdb->get_col("SELECT COUNT(ID) FROM {$table}");

        if (!$found_posts) {
            return false;
        }

        $found_posts = $found_posts[0];

        $progress_bar = WP_CLI\Utils\make_progress_bar(sprintf('[%d] %s', $found_posts, $message), (int) $found_posts, 1);
        $progress_bar->display();

        do {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} LIMIT %d OFFSET %d",
                    array(
                        $step,
                        $offset,
                    )
                )
            );

            if ($results) {
                foreach ($results as $result) {
                    $callback($result);
                    $progress_bar->tick();
                }
            }

            $offset += $step;
        } while ($results);
    }

    /**
     * Outputs a line.
     *
     * @param string $msg
     * @param bool   $verbose
     */
    protected function line($msg, $verbose)
    {
        if ($verbose) {
            WP_CLI::line($msg);
        }
    }

    /**
     * Outputs a log message.
     *
     * @param string $msg
     * @param bool   $verbose
     */
    protected function log($msg, $verbose)
    {
        if ($verbose) {
            WP_CLI::log($msg);
        }
    }

    /**
     * Outputs a success message.
     *
     * @param string $msg
     * @param bool   $verbose
     */
    protected function success($msg, $verbose)
    {
        if ($verbose) {
            WP_CLI::success($msg);
        }
    }

    /**
     * Outputs a warning.
     *
     * @param string $msg
     * @param bool   $verbose
     */
    protected function warning($msg, $verbose)
    {
        if ($verbose) {
            WP_CLI::warning($msg);
        }
    }
}
