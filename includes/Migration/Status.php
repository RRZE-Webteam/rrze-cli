<?php

namespace RRZE\CLI\Migration;

use RRZE\CLI\Command;
use RuntimeException;
use WP_CLI;

final class Status extends Command
{
    /**
     * Reads a migration journal and recovery guidance without modifying WordPress.
     *
     * ## OPTIONS
     *
     * <run-id>
     * : Run ID reported by import all.
     * [--run-dir=<directory>]
     * : Private journal root; defaults to RRZE_MIGRATION_RUN_DIR.
     * [--format=<format>]
     * : Output format: text (default) or json.
     */
    public function __invoke($args, $assoc_args)
    {
        try {
            $root = Run::root($assoc_args['run-dir'] ?? (defined('RRZE_MIGRATION_RUN_DIR') ? RRZE_MIGRATION_RUN_DIR : ''), [ABSPATH, WP_CONTENT_DIR], false);
            $state = Run::read($root, $args[0]);
            $format = $assoc_args['format'] ?? 'text';
            if (!in_array($format, ['json', 'text'], true)) {
                throw new RuntimeException('Choose --format=text or --format=json.');
            }
            $state['recovery'] = [
                'Do not resume or replay individual steps. A started step may have partially completed.',
                'If interrupted, confirm that the parent process and all database/WP-CLI child processes have stopped before any manual cleanup.',
                'Inspect the recorded site and resources. Delete an incomplete site manually in Network Admin before a fresh import.',
                'Keep global users, including accounts created by this run. Never restore global user/network tables over a live installation from this journal.',
                'After manual cleanup, run preflight on the preserved package and import into a new site. Existing identities will be rechecked.',
                'Restoring a site deleted before migration requires its independent backup; this run cannot undo that deletion.',
            ];
            $state['preserved_package'] = $root . '/' . $state['run_id'] . '/package.zip';
            if (!empty($state['uploads']['skipped'])) {
                $state['media_notice'] = Preflight::MANUAL_UPLOADS_NOTICE;
            } elseif (isset($state['uploads']) && !$state['uploads']['included']) {
                $state['media_notice'] = 'Media were absent from the package. A separate media transfer is not verified.';
            }
            if (!$state['active']) {
                $file = $state['preserved_package'];
                $state['package_intact'] = !is_link($file) && is_file($file)
                    && is_string($state['package_sha256']) && hash_equals($state['package_sha256'], hash_file('sha256', $file));
            }
            if ($format === 'json') {
                WP_CLI::line(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                WP_CLI::log('Run: ' . $state['run_id'] . ' — ' . $state['observed_status']);
                WP_CLI::log('Last checkpoint: ' . ($state['step'] ?? 'not started') . '; site ID: ' . ($state['site_id'] ?? 'not recorded'));
                if (isset($state['media_notice'])) {
                    WP_CLI::warning($state['media_notice']);
                }
                if (isset($state['failure_step'])) {
                    WP_CLI::log('Failed step: ' . $state['failure_step']);
                }
                foreach ($state['steps'] as $name => $step) {
                    WP_CLI::log($name . ': ' . $step['status']);
                }
                WP_CLI::log('Preserved package: ' . $state['preserved_package']);
                WP_CLI::log('Package checksum: ' . ($state['active'] ? 'not checked while active' : ($state['package_intact'] ? 'matches' : 'missing or mismatched')));
                foreach ($state['recovery'] as $instruction) {
                    WP_CLI::log($instruction);
                }
            }
        } catch (\Throwable $error) {
            WP_CLI::error($error->getMessage());
        }
    }
}
