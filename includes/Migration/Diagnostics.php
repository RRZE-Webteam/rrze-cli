<?php

namespace RRZE\CLI\Migration;

/** Bounded, origin-only diagnostics. Never record error bodies, arguments or SQL. */
final class Diagnostics
{
    private static ?self $current = null;
    private array $entries = [];
    private int $count = 0;
    private int $overflow = 0;
    private ?string $directory = null;
    private bool $finished = false;

    public static function boot(): void
    {
        if (self::$current !== null || !defined('WP_CLI') || !WP_CLI
            || (\WP_CLI::get_runner()->arguments[0] ?? '') !== 'rrze-migration'
            || \WP_CLI::get_config('debug')) {
            return;
        }
        $diagnostics = self::$current = new self();
        $previous = null;
        $previous = set_error_handler(static function ($severity, $message, $file, $line) use ($diagnostics, &$previous): bool {
            if (error_reporting() & $severity && in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                $diagnostics->record('php_deprecation', $file, $line);
                return true;
            }
            return $previous !== null ? (bool) $previous($severity, $message, $file, $line) : false;
        });
        register_shutdown_function(static fn () => $diagnostics->finish());
    }

    public function record(string $kind, string $origin, int $line = 0): void
    {
        $this->count++;
        $origin = mb_strcut(Terminal::safe($origin), 0, 1024, 'UTF-8');
        $key = hash('sha256', $kind . '|' . $origin . '|' . $line);
        if (isset($this->entries[$key])) {
            $this->entries[$key]['count']++;
        } elseif (count($this->entries) < 100) {
            $this->entries[$key] = ['kind' => $kind, 'origin' => $origin, 'line' => $line, 'count' => 1];
        } else {
            $this->overflow++;
        }
    }

    public static function destination(string $directory): void
    {
        if (self::$current !== null) {
            self::$current->directory = $directory;
        }
    }

    public static function command(string $command, string $stderr, int $code): void
    {
        if (self::$current === null) {
            return;
        }
        // Command names are internal literals; never store command arguments or stderr bodies.
        $command = preg_match('/^[a-z -]{1,80}$/D', $command) ? $command : 'subprocess';
        if ($code !== 0) {
            self::$current->record('command_exit_' . $code, $command);
        } elseif (trim($stderr) !== '') {
            self::$current->record('command_diagnostics', $command);
            // This remains visible even when the successful subprocess contained warnings.
            fwrite(STDERR, '[Diagnostics] ' . $command . ' reported diagnostics; review the diagnostic summary.' . PHP_EOL);
        }
    }

    public function report(): array
    {
        return ['diagnostics_version' => 1, 'events' => $this->count, 'omitted_events' => $this->overflow,
            'entries' => array_values($this->entries), 'message_bodies_recorded' => false];
    }

    public function save(string $directory): string
    {
        if (is_link($directory) || !is_dir($directory) || (fileperms($directory) & 0077) !== 0
            || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) {
            throw new \RuntimeException('Diagnostics require a private, owned directory.');
        }
        $path = $directory . '/diagnostics-' . bin2hex(random_bytes(8)) . '.json';
        $handle = Files::output($path);
        try {
            $contents = json_encode($this->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Could not completely save migration diagnostics.');
            }
        } finally {
            fclose($handle);
        }
        return $path;
    }

    public function finish(): void
    {
        if ($this->finished || $this->count === 0) {
            return;
        }
        $this->finished = true;
        fwrite(STDERR, PHP_EOL . '[Diagnostics] ' . $this->count . ' events from ' . count($this->entries) . ' recorded origins. Message bodies and SQL are not recorded.' . PHP_EOL);
        if ($this->directory !== null) {
            try {
                fwrite(STDERR, 'Private diagnostic report: ' . Terminal::safe($this->save($this->directory)) . PHP_EOL);
                return;
            } catch (\Throwable $failure) {
                fwrite(STDERR, 'Diagnostic report could not be saved; migration status is unchanged.' . PHP_EOL);
            }
        }
        // Preview and cancellation before storage selection leave no persistent files.
        foreach (array_slice($this->entries, 0, 5) as $entry) {
            fwrite(STDERR, '  ' . $entry['kind'] . ': ' . $entry['origin'] . ':' . $entry['line'] . ' (' . $entry['count'] . ')' . PHP_EOL);
        }
    }
}
