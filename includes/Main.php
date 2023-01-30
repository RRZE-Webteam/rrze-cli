<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI;

class Main
{
    /**
     * __construct
     */
    public function __construct()
    {
        if (!class_exists('WP_CLI')) {
            return;
        }
        // rrze-cli
        WP_CLI::add_command('rrze-cli info', __NAMESPACE__ . '\\Info');
        // rrze-migration
        WP_CLI::add_command('rrze-migration export', __NAMESPACE__ . '\\Migration\\Export');
        WP_CLI::add_command('rrze-migration import', __NAMESPACE__ . '\\Migration\\Import');
        WP_CLI::add_command('rrze-migration posts', __NAMESPACE__ . '\\Migration\\Posts');
    }
}
