<?php

namespace RRZE\CLI\Migration;

use RRZE\CLI\Command;
use RuntimeException;
use WP_CLI;

/** External transfer instructions and repeatable, read-only checks of the imported site. */
final class Media extends Command
{
    /**
     * Shows rsync commands for the new website. Run them on the destination host.
     *
     * ## OPTIONS
     *
     * <run-id>
     * : Run ID reported by import all.
     * [--run-dir=<directory>]
     * : Private migration storage; or configure RRZE_MIGRATION_RUN_DIR.
     * [--source-host=<host>]
     * : SSH source as user@hostname. Use an SSH alias for ports or other settings.
     * [--source-dir=<directory>]
     * : Matching media snapshot directory. Without --source-host, explicitly selects a local or mounted snapshot.
     * [--plain]
     * : Show the transfer summary and commands without colors or boxes.
     */
    public function plan($args, $assoc_args): void
    {
        try {
            Console::configure(isset($assoc_args['plain']));
            $root = PackageStorage::root($assoc_args);
            [$state, $manifest, $layout] = self::load($root, $args[0]);
            self::instructions($manifest, $layout, $root . '/' . $args[0], $args[0], $assoc_args['source-host'] ?? null, $assoc_args['source-dir'] ?? null);
        } catch (\Throwable $error) {
            WP_CLI::error($error->getMessage());
        }
    }

    /**
     * Verifies externally transferred media without reimporting or modifying website data.
     *
     * ## OPTIONS
     *
     * <run-id>
     * : Run ID of a successful site-data import.
     * [--run-dir=<directory>]
     * : Private migration storage; or configure RRZE_MIGRATION_RUN_DIR.
     * [--access-checked]
     * : Attest that protected media were tested via HTTP with unauthorized and authorized access. Required for protected files, in addition to rrze-ac configuration checks.
     */
    public function verify($args, $assoc_args): void
    {
        $run = $execution = null;
        $error = null;
        try {
            $root = PackageStorage::root($assoc_args);
            // A wrong installation must not even change this run's verification state.
            self::guard(Run::read($root, $args[0]));
            $run = Run::openMedia($root, $args[0]);
            $execution = new Execution($run);
            [$state, $manifest, $layout] = self::load($root, $args[0], true);
            $accessChecked = $assoc_args['access-checked'] ?? false;
            if (!is_bool($accessChecked)) {
                throw new RuntimeException('Use --access-checked as a flag after performing the HTTP access checks.');
            }
            $result = $execution->step('verify_media', static function () use ($manifest, $layout, $state, $run, $accessChecked): array {
                $files = MediaManifest::verify($manifest, $layout['directory']);
                $attachments = MediaTransfer::verifySite($state, $run->directory);
                if (MediaTransfer::requiresAccessCheck($manifest) && !$accessChecked) {
                    throw new RuntimeException('File checks passed. Test protected media with unauthorized and authorized HTTP access, then repeat with --access-checked. Filesystem checks alone cannot confirm web-server access protection.');
                }
                return $files + $attachments;
            }, static fn () => self::guard($state));
            $run->mediaVerified($result, $accessChecked);
        } catch (\Throwable $failure) {
            $error = $failure->getMessage();
        } finally {
            try { $execution?->close(); } catch (\Throwable $failure) { $error = 'Could not release the migration lock.'; }
            try { $run?->finish($error === null ? 'completed' : 'media_pending'); } catch (\Throwable $failure) { $error = 'Could not persist the media verification status.'; }
        }
        if ($error !== null) {
            WP_CLI::error($error . ($run ? ' Media remain pending. Correct the transfer or configuration and repeat media verify; do not reimport the website.' : ''));
        }
        WP_CLI::success('Media verified: ' . $result['files'] . ' files, ' . $result['attachments'] . ' attachments. Migration completed.');
    }

    public static function installation(): string
    {
        global $wpdb;
        return hash('sha256', DB_HOST . '|' . DB_NAME . '|' . $wpdb->base_prefix . '|' . realpath(ABSPATH));
    }

    private static function guard(array $state): void
    {
        if (($state['installation'] ?? null) !== self::installation() || ($state['network_id'] ?? null) !== get_current_network_id()
            || !is_int($state['site_id'] ?? null) || !is_string($state['destination'] ?? null)) {
            throw new RuntimeException('This media plan belongs to a different WordPress installation or network.');
        }
        Verification::site($state['site_id'], $state['run_id'], SiteAddress::parse($state['destination']));
    }

    private static function load(string $root, string $id, bool $ownLock = false): array
    {
        $state = Run::read($root, $id);
        self::guard($state);
        if ((!$ownLock && $state['active']) || !in_array($state['status'], ['media_pending', 'completed'], true)
            || ($state['uploads']['transport'] ?? null) !== 'rsync') {
            throw new RuntimeException('Finish the site-data import before preparing or checking the media transfer.');
        }
        $directory = $root . '/' . $id;
        $package = $directory . '/package.zip';
        foreach (['package.zip', 'media.json', 'media-files.txt'] as $name) {
            $file = $directory . '/' . $name;
            if (is_link($file) || !is_file($file) || (fileperms($file) & 0077) !== 0) {
                throw new RuntimeException('A private media plan file is missing or unsafe.');
            }
        }
        if (!is_string($state['package_sha256']) || !hash_equals($state['package_sha256'], hash_file('sha256', $package))) {
            throw new RuntimeException('The preserved migration package has changed.');
        }
        $manifest = Package::read($package, null)['media'];
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (filesize($directory . '/media.json') !== strlen($json) || hash_file('sha256', $directory . '/media.json') !== hash('sha256', $json)
            || hash_file('sha256', $directory . '/media-files.txt') !== hash('sha256', MediaManifest::fileList($manifest))) {
            throw new RuntimeException('The media transfer plan does not match the preserved package.');
        }
        $layout = MediaTransfer::layout($state['destination'], $state['site_id']);
        if ($layout !== ($state['media_destination'] ?? null)) {
            throw new RuntimeException('The destination media location changed after import. Restore the reviewed configuration before continuing.');
        }
        return [$state, $manifest, $layout];
    }

    public static function instructions(array $manifest, array $layout, string $directory, string $id, ?string $host = null, ?string $source = null): void
    {
        $console = Console::get();
        $commands = MediaTransfer::commands($manifest, $layout, $directory . '/media-files.txt', $host, $source, true);
        $remote = $host !== null || $source === null;
        $console->summary('Media transfer', [
            'Source URL' => $manifest['source_baseurl'],
            'Destination URL' => $layout['baseurl'],
            'Transfer' => $remote ? 'Remote via SSH (' . ($host ?? 'source host required') . ')' : 'Local / mounted directory',
            'Files' => count($manifest['files']) . ' (' . number_format(array_sum(array_column($manifest['files'], 'bytes')) / 1048576, 1) . ' MiB)',
            'Excluded directories' => implode(', ', $manifest['excluded_directories']) ?: 'none',
        ]);
        $console->line('Source media directory: ' . ($source ?? $manifest['source_directory']));
        $console->line('Destination media directory: ' . $layout['directory']);
        $console->line('Run these commands on the destination host. Use a frozen snapshot matching the export.');
        $console->line('Only inventoried files are selected; existing files are not overwritten or deleted.');
        if ($remote) {
            $console->line('Remote transfer requires rsync 3.0+ on both hosts (--protect-args).');
        }
        if (MediaTransfer::requiresAccessCheck($manifest)) {
            WP_CLI::warning('Before transferring protected media, configure rrze-ac and web-server access rules. Test unauthorized and authorized HTTP access before confirming --access-checked.');
        }
        if ($host === null && $source === null) {
            $console->line('Replace SOURCE_USER@SOURCE_HOST for SSH. For a local transfer, regenerate the commands with:');
            $console->line();
            foreach (explode("\n", Console::shell(['wp', 'rrze-migration', 'media', 'plan', $id, '--run-dir=' . dirname($directory), '--source-dir=' . $manifest['source_directory']])) as $line) {
                $console->line($line);
            }
        }
        $console->command('1. Preview', 'Shows the planned copies without transferring files.', $commands['preview']);
        $console->command('2. Transfer', 'Run after reviewing the preview. Copies missing files only.', $commands['transfer']);
        $console->command('3. Verify', 'Run after the transfer. Checks completeness, checksums and WordPress media references.',
            Console::shell(['wp', 'rrze-migration', 'media', 'verify', $id, '--run-dir=' . dirname($directory)]));
    }

}
