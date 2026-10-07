<?php

namespace RRZE\CLI\Migration;

use RRZE\CLI\Command;
use RuntimeException;
use WP_CLI;

final class Wizard extends Command
{
    /**
     * Guides an interactive export or import using the same migration safeguards.
     *
     * ## OPTIONS
     *
     * [<operation>]
     * : import or export; asks if omitted. Requires an interactive terminal.
     *
     * ## EXAMPLES
     *
     *     wp rrze-migration wizard export --url=https://source.example.test/site/
     *     wp rrze-migration wizard import
     */
    public function __invoke($args, $assoc_args)
    {
        $terminal = new Terminal(STDIN, STDOUT);
        $handlers = [];
        $async = false;
        try {
            $config = WP_CLI::get_config();
            if ($assoc_args || !empty($config['yes']) || !empty($config['quiet'])) {
                throw new RuntimeException('The wizard requires visible, explicit answers; --yes, --quiet and command options are not supported. Use export all or import all for automation.');
            }
            if (!$terminal->interactive()) {
                throw new RuntimeException('The wizard requires an interactive input and output terminal. Use export all or import all --dry-run for scripts.');
            }
            if (function_exists('pcntl_async_signals')) {
                $async = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (): void {
                        throw new RuntimeException('Wizard cancelled by a signal.');
                    }, false);
                }
            }
            $terminal->line('RRZE migration wizard — type !quit at any prompt to cancel.');
            $terminal->line('WordPress directory: ' . ABSPATH);
            $terminal->line('Current website: ' . home_url() . ' (site ID ' . get_current_blog_id() . ')');
            if (is_multisite()) {
                $terminal->line('Current network: ' . get_current_network_id() . ' — ' . network_home_url());
            }
            $operation = $args[0] ?? $terminal->choice('Operation', ['import', 'export'], 'import');
            if ($operation === 'export') {
                $this->exportSite($terminal);
            } elseif ($operation === 'import') {
                $this->importSite($terminal);
            } else {
                throw new RuntimeException('Choose wizard import or wizard export.');
            }
        } catch (\Throwable $error) {
            // Restore signal handlers before WP_CLI::error exits the process.
            $failure = $error->getMessage();
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($handlers) {
                pcntl_async_signals($async);
            }
        }
        if (isset($failure)) {
            WP_CLI::error(Terminal::safe($failure));
        }
    }

    private function exportSite(Terminal $terminal): void
    {
        $terminal->line('The current website is the export source. Select another source with the global --url option before starting.');
        $root = $this->storage($terminal);
        $file = $terminal->ask('New ZIP filename (without a directory)', 'website.zip', static function ($value) use ($root): void {
            PackageStorage::exportPath($root, $value, get_current_blog_id());
        });
        $terminal->line('Subsite-owned tables are selected automatically. Main-site custom tables need explicit selection after an ownership check.');
        $tables = $terminal->ask('Additional site-owned tables, comma-separated (optional)');
        $media = $terminal->choice('Include uploads', ['yes', 'no'], 'yes');
        $options = ['run-dir' => $root];
        if ($tables !== '') {
            $options['custom-tables'] = $tables;
        }
        if ($media === 'yes') {
            $options['uploads'] = true;
            $terminal->line('Upload directories are included unless explicitly excluded. Server configuration and executable files block the export.');
            $options['exclude-upload-dirs'] = $terminal->ask('Upload subdirectories to exclude, comma-separated (optional; e.g. wp-migrate-db)');
        }
        (new Export(static function (array $plan) use ($terminal): bool {
            $terminal->line('Export plan');
            $terminal->line('Source: ' . $plan['source'] . ' (site ID ' . $plan['site_id'] . ')');
            $terminal->line('Output: ' . $plan['output']);
            foreach ($plan['tables'] as $table) {
                $terminal->line('Table: ' . $table);
            }
            $terminal->line('Uploads: ' . ($plan['uploads'] ? 'included' : 'NOT included; separate transfer required'));
            $terminal->line('Excluded upload directories: ' . (implode(', ', $plan['excluded_upload_directories']) ?: 'none'));
            $terminal->line('Plugins and themes must be provided separately. SSO logins stay unchanged; user credentials are excluded.');
            return $terminal->identity('Export source', $plan['source']) && $terminal->confirm('Create this export package now');
        }))->all([$file], $options);
    }

    private function importSite(Terminal $terminal): void
    {
        if (!is_multisite()) {
            throw new RuntimeException('Migration requires a multisite destination and always creates a new site.');
        }
        $terminal->line('Existing destinations must be deleted manually in Network Admin. The wizard never deletes or overwrites a website.');
        $options = ['run-dir' => $this->storage($terminal)];
        $file = $terminal->ask('ZIP package (relative to the private migration directory, or absolute)', '', static function ($value) use ($options): void {
            PackageStorage::input($value, $options);
        });
        $url = $terminal->ask('New destination URL (including the website path)', '', static function ($value): void {
            if (!preg_match('~^https?://~i', $value)) {
                throw new RuntimeException('Enter the complete destination URL starting with http:// or https://.');
            }
            Destination::available(SiteAddress::parse($value));
        });
        $fields = $terminal->ask('Post meta keys with numeric user IDs, comma-separated (optional)');
        $options += ['new_url' => $url, 'uid_fields' => $fields];
        $withoutUploads = static function (string $limitation) use ($terminal): bool {
            $terminal->line('Upload limitation: ' . $limitation);
            $terminal->line(Preflight::MANUAL_UPLOADS_NOTICE);
            return $terminal->confirm('Continue without uploads');
        };
        if ($terminal->choice('Next action', ['preview', 'import'], 'preview') === 'preview') {
            (new Import(null, $withoutUploads))->all([$file], $options + ['dry-run' => true]);
            return;
        }
        (new Import(static function (array $plan) use ($terminal): bool {
            $terminal->line('Import plan');
            foreach (Plan::lines($plan) as $line) {
                $terminal->line($line);
            }
            $terminal->line('Existing global users remain unchanged; missing WordPress accounts receive random local passwords. This does not create SSO identities.');
            $terminal->line('The private package copy and journal are retained even after cancellation. A failed import may leave an incomplete site for manual recovery.');
            if (!$plan['uploads']['skipped'] && !$plan['uploads']['included'] && !$terminal->confirm('Media are absent; a separate transfer is not verified. Continue')) {
                return false;
            }
            if (!$plan['uploads']['skipped'] && ($plan['uploads']['excluded_directories'] ?? []) && !$terminal->confirm('Listed upload directories were excluded and will not be restored. Continue')) {
                return false;
            }
            return $terminal->identity('New destination', $plan['destination']) && $terminal->confirm('Create the new website and execute this plan now');
        }, $withoutUploads))->all([$file], $options);
    }

    private function storage(Terminal $terminal): string
    {
        $root = $terminal->ask('Private migration directory outside web roots', defined('RRZE_MIGRATION_RUN_DIR') ? RRZE_MIGRATION_RUN_DIR : '', static function ($value): void {
            PackageStorage::root(['run-dir' => $value]);
        });
        $terminal->line('ZIP packages belong in this private directory. Each export and import uses its own subdirectory.');
        return $root;
    }
}
