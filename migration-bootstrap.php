<?php

/** Optional WP-CLI --require entry point: collect deprecations before WordPress loads. */
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}
require_once __DIR__ . '/vendor/autoload.php';
\RRZE\CLI\Migration\Diagnostics::boot();
