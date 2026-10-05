<?php

declare(strict_types=1);

// Load no WordPress installation or wp-config.php in the unit suite.
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/vendor/autoload.php';
