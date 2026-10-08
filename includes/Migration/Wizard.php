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
     * [--site-id=<id>]
     * : Source Multisite website ID for export; asks if omitted.
     * [--plain]
     * : Use line-oriented prompts without terminal controls (also used with --no-color).
     * [--verbose]
     * : Show individual table mappings and user actions in the review.
     *
     * ## EXAMPLES
     *
     *     wp rrze-migration wizard export --url=https://source.example.test/site/
     *     wp rrze-migration wizard export --site-id=5
     *     wp rrze-migration wizard import
     */
    public function __invoke($args, $assoc_args)
    {
        $handlers = [];
        $async = false;
        try {
            $config = WP_CLI::get_config();
            if (array_diff(array_keys($assoc_args), ['site-id', 'plain', 'verbose']) || !empty($config['yes']) || !empty($config['quiet'])) {
                throw new RuntimeException('The wizard requires visible, explicit answers. --yes and --quiet are not allowed. Use export all or import all for automation.');
            }
            Console::configure(isset($assoc_args['plain']), isset($assoc_args['verbose']));
            $terminal = new Terminal(STDIN, STDOUT, Console::get()->rich);
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
            if ($terminal->rich) {
                Console::get()->heading('RRZE Migration');
                $terminal->line('Arrows to select · Enter to confirm · Ctrl+C to cancel · --plain for text mode');
            } else {
                $terminal->line('RRZE migration wizard — type !quit at any prompt to cancel.');
            }
            $terminal->line('WordPress directory: ' . ABSPATH);
            $terminal->line('Current website: ' . home_url() . ' (site ID ' . get_current_blog_id() . ')');
            if (is_multisite()) {
                $terminal->line('Current network: ' . get_current_network_id() . ' — ' . network_home_url());
            }
            $operation = $args[0] ?? $terminal->choice('Operation', ['import', 'export'], 'import');
            if ($operation === 'export') {
                $sourceOptions = $assoc_args;
                if (is_multisite() && !array_key_exists('site-id', $sourceOptions)) {
                    $selected = 'manual';
                    if ($terminal->rich) {
                        // One extra result detects larger networks without loading every website.
                        $availableSites = get_sites(['network_id' => get_current_network_id(), 'number' => 21, 'orderby' => 'id', 'order' => 'ASC']);
                        if (count($availableSites) <= 20) {
                            $sites = ['manual' => 'Enter a website ID'];
                            foreach ($availableSites as $site) {
                                $sites['site-' . $site->blog_id] = 'ID ' . $site->blog_id . ' | ' . get_home_url($site->blog_id);
                            }
                            $selected = $terminal->select('Export website', $sites, 'manual');
                        }
                    }
                    $sourceOptions['site-id'] = $selected === 'manual'
                        ? $terminal->ask('Source website ID', (string) get_current_blog_id(), static fn ($value) => ExportSource::resolve($value))
                        : substr($selected, 5);
                }
                $forward = array_key_exists('site-id', $sourceOptions)
                    ? ExportSource::command('rrze-migration wizard', ['export'], $sourceOptions) : null;
                if ($forward === null) {
                    $this->exportSite($terminal);
                } else {
                    $terminal->line('Loading the selected website context before confirming the export source...');
                }
            } elseif ($operation === 'import') {
                if (array_key_exists('site-id', $assoc_args)) {
                    throw new RuntimeException('--site-id selects an export source and is not supported for import.');
                }
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
            Console::get()->failure($failure, export: ($operation ?? null) === 'export');
        }
        if (isset($forward)) {
            // Preserve the real terminal, but restore this process's signal handlers before handing it over.
            WP_CLI::runcommand($forward);
        }
    }

    private function exportSite(Terminal $terminal): void
    {
        $sourceId = get_current_blog_id();
        $sourceUrl = home_url();
        $terminal->line('Export source: ID ' . $sourceId . ' | URL: ' . $sourceUrl);
        if (!$terminal->confirm('Export this website')) {
            throw new RuntimeException('Export cancelled. No output file was created.');
        }
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
        (new Export(static function (array $plan) use ($terminal, $sourceId, $sourceUrl): bool {
            if ($plan['site_id'] !== $sourceId || $plan['source'] !== $sourceUrl) {
                throw new RuntimeException('The export source changed after confirmation. Start the wizard again to review the current website.');
            }
            Console::get()->exportPlan($plan);
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
        $file = $this->package($terminal, $options);
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
            Console::get()->plan($plan);
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

    private function package(Terminal $terminal, array $options): string
    {
        $listing = PackageStorage::packages($options);
        if ($terminal->rich && $listing['files']) {
            $optionsList = ['manual' => 'Enter a private ZIP path'];
            foreach ($listing['files'] as $index => $file) {
                $optionsList['zip-' . $index] = '#' . ($index + 1) . ' ' . $file['path'] . ' | '
                    . gmdate('Y-m-d H:i', $file['modified']) . ' UTC | ' . number_format($file['bytes'] / 1048576, 1) . ' MiB';
            }
            if ($listing['incomplete']) {
                $terminal->line('Package list is limited; an unlisted ZIP can be entered by path.');
            }
            $selected = $terminal->select('Import package (newest first)', $optionsList, 'manual');
            if ($selected !== 'manual') {
                $path = PackageStorage::input($listing['files'][(int) substr($selected, 4)]['path'], $options);
                $terminal->line('Selected package: ' . $path);
                return $path;
            }
        }
        $choices = [];
        if ($listing['files']) {
            $terminal->line('Available ZIP packages (newest modification first; contents checked after selection):');
            foreach ($listing['files'] as $index => $file) {
                $number = $index + 1;
                $choices[$number] = $file['path'];
                $terminal->line(sprintf('  [%d] %s | %s UTC | %.1f MiB', $number, $file['path'], gmdate('Y-m-d H:i:s', $file['modified']), $file['bytes'] / 1048576));
            }
        } else {
            $terminal->line('No ZIP packages found in the searched directories. Enter a private ZIP path manually.');
        }
        if ($listing['incomplete']) {
            $terminal->line('The list is incomplete (up to 50 ZIPs, 5000 entries, 3 subdirectory levels). You can enter an unlisted path manually.');
        }
        $terminal->line('Enter a list number or a relative/absolute path. No package is selected by default.');
        $selected = '';
        $terminal->ask('ZIP package (relative to the private migration directory, absolute, or list number)', '', static function ($value) use ($choices, $options, &$selected): void {
            if (preg_match('/^[0-9]+$/D', $value)) {
                if (!isset($choices[$value])) {
                    throw new RuntimeException('Choose a displayed package number or enter a private ZIP path.');
                }
                $value = $choices[$value];
            }
            // Recheck the chosen path; files may disappear or be replaced while the prompt is open.
            $selected = PackageStorage::input($value, $options);
        });
        $terminal->line('Selected ZIP: ' . $selected);
        return $selected;
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
