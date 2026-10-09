<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Private, durable checkpoints. Never serialize exceptions, SQL, profiles or credentials. */
final class Run
{
    public const STEPS = ['prepare', 'acquire_lock', 'recheck', 'create_site', 'import_tables', 'replace_urls', 'configure_site', 'import_users', 'remap_references', 'import_uploads', 'prepare_media', 'verify_media', 'finalize', 'verify', 'cleanup'];
    public readonly string $id;
    public readonly string $directory;
    private array $state;
    private $handle;
    private bool $closed = false;
    private string $reserve;

    private function __construct(string $root, ?string $existing = null)
    {
        $this->id = $existing ?? bin2hex(random_bytes(16));
        $this->directory = $root . '/' . $this->id;
        if ($existing !== null) {
            self::read($root, $existing);
            $this->handle = fopen($this->directory . '/active.lock', 'rb');
            if (!$this->handle || !flock($this->handle, LOCK_EX | LOCK_NB)) {
                if (is_resource($this->handle)) { fclose($this->handle); }
                throw new RuntimeException('This migration run is active. Wait before checking media.');
            }
            try {
                $this->state = self::read($root, $existing);
                unset($this->state['active'], $this->state['observed_status']);
                if (!in_array($this->state['status'], ['media_pending', 'completed'], true)
                    || ($this->state['uploads']['transport'] ?? null) !== 'rsync') {
                    throw new RuntimeException('Only a successful site import with an external media plan can be checked. Read status for recovery guidance.');
                }
                $this->state['status'] = 'media_pending';
                $this->state['uploads']['verified'] = false;
                $this->state['uploads']['status'] = 'pending';
                $this->state['uploads']['manual_transfer_required'] = true;
                unset($this->state['steps']['verify_media'], $this->state['media_verification']);
                $this->save();
            } catch (\Throwable $error) {
                fclose($this->handle);
                throw $error;
            }
        } else {
            if (!mkdir($this->directory, 0700)) {
                throw new RuntimeException('Cannot create the private migration run directory.');
            }
            $this->handle = Files::output($this->directory . '/active.lock');
            if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Cannot lock the new migration journal.');
            }
            $this->state = [
                'journal_version' => 1, 'run_id' => $this->id, 'status' => 'running',
                'started_at' => gmdate('c'), 'updated_at' => gmdate('c'), 'step' => null,
                'steps' => [], 'site_id' => null, 'users' => [], 'pending_user' => null,
                'package_sha256' => null,
            ];
            $this->save();
        }
        $this->reserve = str_repeat('x', 65536);
        register_shutdown_function(function (): void {
            $this->reserve = '';
            if (!$this->closed) {
                // Also runs on native exit()/fatal errors. SIGKILL remains observable as an unlocked running journal.
                try {
                    $this->finish(($this->state['status'] ?? '') === 'media_pending' ? 'media_pending' : 'interrupted');
                } catch (\Throwable $ignored) {
                    // Last durable checkpoint remains authoritative; never log the fatal error text.
                }
            }
        });
    }

    public static function root(string $path, array $webRoots, bool $create, bool $allowMissing = false): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '://')
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path) || is_link($path)) {
            throw new RuntimeException('Provide an absolute private --run-dir outside the web roots, or configure RRZE_MIGRATION_RUN_DIR.');
        }
        $parent = realpath(dirname(rtrim($path, '/')));
        $resolved = realpath($path) ?: ($parent === false ? '' : $parent . '/' . basename($path));
        if ($resolved === '') {
            throw new RuntimeException('The parent of the migration run directory must already exist.');
        }
        foreach ($webRoots as $webRoot) {
            $webRoot = realpath($webRoot);
            if ($webRoot !== false && ($resolved === $webRoot || str_starts_with($resolved, $webRoot . '/'))) {
                throw new RuntimeException('Migration runs must be stored outside the web roots.');
            }
        }
        if (!file_exists($resolved) && $create && !mkdir($resolved, 0700)) {
            throw new RuntimeException('Cannot create the migration run directory.');
        }
        if (!file_exists($resolved) && $allowMissing) {
            return $resolved;
        }
        clearstatcache(true, $resolved);
        if (!is_dir($resolved) || (fileperms($resolved) & 0077) !== 0
            || (function_exists('posix_geteuid') && fileowner($resolved) !== posix_geteuid())) {
            throw new RuntimeException('The migration run directory must be owned by this user with private permissions (0700).');
        }
        return $resolved;
    }

    public static function create(string $root): self
    {
        return new self($root);
    }

    public static function openMedia(string $root, string $id): self
    {
        return new self($root, $id);
    }

    public function mediaPlan(array $layout, bool $accessRequired): void
    {
        $this->state['uploads_directory'] = $layout['directory'];
        $this->state['media_destination'] = $layout;
        $this->state['uploads']['access_check_required'] = $accessRequired;
        $this->save();
    }

    public function mediaVerified(array $result, bool $accessChecked): void
    {
        if (($this->state['steps']['verify_media']['status'] ?? null) !== 'completed') {
            throw new RuntimeException('Media checks must finish before recording verification.');
        }
        $this->state['uploads']['verified'] = true;
        $this->state['uploads']['status'] = 'verified';
        $this->state['uploads']['manual_transfer_required'] = false;
        $this->state['media_verification'] = $result + ['checked_at' => gmdate('c'), 'access_checked_by_operator' => $accessChecked];
        $this->save();
    }

    public function package(string $input): string
    {
        if (!is_file($input) || !is_readable($input) || filesize($input) > Package::LIMITS['archive']) {
            throw new RuntimeException('The import package is unreadable or exceeds the archive size limit.');
        }
        Files::capacity($this->directory, filesize($input) + 16777216);
        $source = fopen($input, 'rb');
        $output = Files::output($this->directory . '/package.zip');
        try {
            $copied = stream_copy_to_stream($source, $output, Package::LIMITS['archive'] + 1);
            if ($copied === false || $copied > Package::LIMITS['archive'] || fread($source, 1) !== '' || !fflush($output) || !fsync($output)) {
                throw new RuntimeException('Cannot completely preserve the import package.');
            }
        } finally {
            fclose($source);
            fclose($output);
        }
        $this->state['package_sha256'] = hash_file('sha256', $this->directory . '/package.zip');
        $this->save();
        return $this->directory . '/package.zip';
    }

    public function plan(array $plan, string $installation): void
    {
        $this->state['installation'] = $installation;
        $this->state['source'] = $plan['source']['url'];
        $this->state['destination'] = $plan['target']['url'];
        $this->state['network_id'] = $plan['destination']['network_id'];
        $this->state['tables'] = array_values($plan['mapping']);
        $this->state['uploads_directory'] = $plan['destination']['uploads_directory'];
        $this->state['uploads'] = $plan['report']['uploads'];
        $this->state['user_reference_fields'] = $plan['fields'];
        $this->save();
    }

    public function begin(string $step): void
    {
        if (!in_array($step, self::STEPS, true) || isset($this->state['steps'][$step])) {
            throw new RuntimeException('Invalid or repeated migration step.');
        }
        $this->state['step'] = $step;
        $this->state['steps'][$step] = ['status' => 'started', 'started_at' => gmdate('c')];
        $this->save();
    }

    public function done(): void
    {
        if (($this->state['steps'][$this->state['step']]['status'] ?? null) !== 'started') {
            throw new RuntimeException('No unfinished migration step to complete.');
        }
        $this->state['steps'][$this->state['step']]['status'] = 'completed';
        $this->state['steps'][$this->state['step']]['finished_at'] = gmdate('c');
        $this->save();
    }

    public function site(int $id): void
    {
        $this->state['site_id'] = $id;
        $this->save();
    }

    public function workspace(string $path): void
    {
        $this->state['workspace'] = $path;
        $this->save();
    }

    public function baseline(array $hashes): void
    {
        foreach ($hashes as $id => $hash) {
            if (!is_int($id) || $id < 1 || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Invalid protected-user fingerprint.');
            }
        }
        $this->state['protected_users'] = $hashes;
        $this->save();
    }

    public function failure(): void
    {
        $this->state['failure_step'] = $this->state['step'];
        $this->save();
    }

    public function userIntent(int $sourceId): void
    {
        $this->state['pending_user'] = $sourceId;
        $this->save();
    }

    public function user(int $sourceId, int $targetId, bool $created): void
    {
        $this->state['users'][(string) $sourceId] = ['target_id' => $targetId, 'created' => $created];
        $this->state['pending_user'] = null;
        $this->save();
    }

    public function finish(string $status): void
    {
        if (!in_array($status, ['completed', 'media_pending', 'failed', 'interrupted'], true)) {
            throw new RuntimeException('Invalid migration result.');
        }
        if (in_array($status, ['completed', 'media_pending'], true) && (($this->state['steps']['verify']['status'] ?? null) !== 'completed'
            || ($this->state['steps']['cleanup']['status'] ?? null) !== 'completed')) {
            throw new RuntimeException('Verification and cleanup must complete before migration success.');
        }
        if ($status === 'completed' && ($this->state['uploads']['transport'] ?? null) === 'rsync'
            && (($this->state['steps']['verify_media']['status'] ?? null) !== 'completed' || empty($this->state['uploads']['verified']))) {
            throw new RuntimeException('External media verification must complete before migration success.');
        }
        $this->state['status'] = $status;
        $this->save();
        $this->closed = true;
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    private function save(): void
    {
        $this->state['updated_at'] = gmdate('c');
        $path = $this->directory . '/checkpoint-' . bin2hex(random_bytes(8));
        $handle = Files::output($path);
        try {
            $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist the migration checkpoint.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($path, $this->directory . '/run.json')) {
            throw new RuntimeException('Cannot publish the migration checkpoint.');
        }
    }

    public static function read(string $root, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('Invalid migration run ID.');
        }
        $directory = $root . '/' . $id;
        foreach ([$directory, $directory . '/run.json', $directory . '/active.lock'] as $path) {
            if (is_link($path) || !file_exists($path) || (fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('Missing or unsafe migration journal.');
            }
        }
        if (filesize($directory . '/run.json') > 16777216) {
            throw new RuntimeException('The migration journal exceeds the supported size.');
        }
        $handle = fopen($directory . '/active.lock', 'rb');
        try {
            $active = !flock($handle, LOCK_EX | LOCK_NB);
            $state = json_decode(file_get_contents($directory . '/run.json'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['journal_version'] ?? null) !== 1 || ($state['run_id'] ?? null) !== $id
                || !in_array($state['status'] ?? null, ['running', 'completed', 'media_pending', 'failed', 'interrupted'], true)
                || !is_array($state['steps'] ?? null) || !array_key_exists('site_id', $state)
                || ($state['site_id'] !== null && (!is_int($state['site_id']) || $state['site_id'] < 2))
                || !array_key_exists('package_sha256', $state)
                || ($state['package_sha256'] !== null && (!is_string($state['package_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $state['package_sha256'])))) {
                throw new RuntimeException('Invalid migration journal.');
            }
            foreach ($state['steps'] as $step => $details) {
                if (!in_array($step, self::STEPS, true) || !is_array($details)
                    || !in_array($details['status'] ?? null, ['started', 'completed', 'skipped'], true)) {
                    throw new RuntimeException('Invalid migration step checkpoint.');
                }
                if ($details['status'] === 'skipped' && ($step !== 'import_uploads'
                    || ($state['uploads']['skipped'] ?? null) !== true
                    || ($details['reason'] ?? null) !== 'manual_transfer')) {
                    throw new RuntimeException('Invalid skipped migration step checkpoint.');
                }
            }
            if (in_array($state['status'], ['completed', 'media_pending'], true) && (($state['steps']['verify']['status'] ?? null) !== 'completed'
                || ($state['steps']['cleanup']['status'] ?? null) !== 'completed')) {
                throw new RuntimeException('Incomplete journal cannot establish migration success.');
            }
            if ($state['status'] === 'completed' && ($state['uploads']['transport'] ?? null) === 'rsync'
                && (($state['steps']['verify_media']['status'] ?? null) !== 'completed' || empty($state['uploads']['verified']))) {
                throw new RuntimeException('Incomplete media checkpoint cannot establish migration success.');
            }
            $state['active'] = $active;
            $state['observed_status'] = $active ? 'active' : ($state['status'] === 'running' ? 'interrupted_or_unfinished' : $state['status']);
            return $state;
        } finally {
            fclose($handle);
        }
    }
}
