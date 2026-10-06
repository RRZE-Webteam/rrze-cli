<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** A process-wide guard and checkpoints; interruption never implies rollback. */
final class Execution
{
    private string $lock;
    private bool $cancelled = false;
    private array $handlers = [];
    private bool $async = false;

    public function __construct(public readonly Run $run)
    {
        global $wpdb;
        // Serialize all migrations sharing global users/site IDs, including imports to different URLs.
        $this->lock = 'rrze-migration-' . substr(hash('sha256', DB_NAME . '|' . $wpdb->base_prefix), 0, 48);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lock)) !== 1) {
            throw new RuntimeException('Another migration is running for this installation, or the migration lock is unavailable.');
        }
        if (function_exists('pcntl_async_signals')) {
            $this->async = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                $this->handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (): void { $this->cancelled = true; });
            }
        }
    }

    public function assertOwned(): void
    {
        global $wpdb;
        if ($this->cancelled) {
            throw new RuntimeException('Migration cancellation requested; stopped at a step boundary.');
        }
        $owner = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock));
        if ((int) $owner !== 1) {
            throw new RuntimeException('The migration database lock was lost.');
        }
    }

    public function step(string $name, callable $action, ?callable $guard = null): mixed
    {
        $this->assertOwned();
        if ($guard) {
            $guard();
        }
        $this->run->begin($name);
        \WP_CLI::log(match ($name) {
            'recheck' => 'Rechecking the destination under the migration lock...',
            'create_site' => 'Creating the new destination website...',
            'import_tables' => 'Importing site tables...',
            'replace_urls' => 'Replacing source URLs...',
            'configure_site' => 'Configuring the new website...',
            'import_users' => 'Mapping SSO users and adding site memberships...',
            'remap_references' => 'Mapping author and user references...',
            'import_uploads' => 'Transferring packaged media...',
            'finalize' => 'Updating the new website rewrite rules...',
            'verify' => 'Verifying the imported website and protected user accounts...',
            default => 'Migration step: ' . $name,
        });
        do_action('rrze_migration_before_step', $name, $this->run->id);
        $this->assertOwned();
        if ($guard) {
            $guard();
        }
        $result = $action();
        $this->assertOwned();
        if ($guard) {
            $guard();
        }
        do_action('rrze_migration_after_step', $name, $this->run->id);
        $this->assertOwned();
        $this->run->done();
        return $result;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function close(): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock));
        foreach ($this->handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($this->handlers) {
            pcntl_async_signals($this->async);
        }
    }
}
