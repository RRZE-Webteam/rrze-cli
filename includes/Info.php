<?php

namespace RRZE\CLI;

defined('ABSPATH') || exit;

use WP_CLI_Command;

/**
 * @package RRZE\CLI
 */
class Info extends WP_CLI_Command
{
    /**
     * Displays General Info about RRZE-CLI
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function __invoke($args, $assoc_args)
    {
        \cli\line('RRZE-CLI version: %Yv' . plugin()->getVersion() . '%n');
        \cli\line();
    }
}
