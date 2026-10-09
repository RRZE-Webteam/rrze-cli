<?php

namespace RRZE\CLI\Migration;

/** Presentation only. No decisions about permissions, confirmation or migration state. */
final class Console
{
    private static ?self $current = null;
    private bool $verbose = false;

    public function __construct(private $output, public readonly bool $rich = false, private readonly bool $quiet = false)
    {
    }

    public static function get(): self
    {
        return self::$current ??= new self(STDOUT, stream_isatty(STDOUT)
            && \WP_CLI::get_config('color') !== false && !\WP_CLI::get_config('quiet') && getenv('TERM') !== 'dumb',
            (bool) \WP_CLI::get_config('quiet'));
    }

    public static function configure(bool $plain, bool $verbose = false): void
    {
        $rich = !$plain && self::get()->rich;
        self::$current = new self(STDOUT, $rich, self::get()->quiet);
        self::$current->verbose = $verbose;
    }

    public function line(string $message = ''): void
    {
        if (!$this->quiet) {
            fwrite($this->output, Terminal::safe($message) . PHP_EOL);
        }
    }

    public function heading(string $title): void
    {
        $this->line();
        $this->status('•', $title, '36;1');
        $this->line();
    }

    /** Render informational text only; shell commands stay outside the box. */
    public function summary(string $title, array $values): void
    {
        if ($this->quiet) {
            return;
        }
        $lines = [];
        foreach ($values as $label => $value) {
            $lines[] = Terminal::safe($label . ': ' . $value);
        }
        if (!$this->rich) {
            $this->heading($title);
            foreach ($lines as $line) {
                $this->line($line);
            }
            return;
        }
        $box = new \Laravel\Prompts\Callout(Terminal::safe($title), implode(PHP_EOL, $lines));
        $box->state = 'submit';
        // Render into this console's stream without resetting an active wizard's
        // shared prompt state. Metadata stays literal, even if it contains markup.
        $renderer = new class($box) extends \Laravel\Prompts\Themes\Default\CalloutRenderer {
            protected function autoFormat(string $text): string
            {
                $this->minWidth = max(1, min(80, \Laravel\Prompts\Prompt::terminal()->cols() - 6));
                return $text;
            }

            protected function ansiWordwrap(string $text, int $width): array
            {
                return explode(PHP_EOL, $this->mbWordwrap($text, $width, PHP_EOL, true));
            }
        };
        fwrite($this->output, (string) $renderer($box));
    }

    /** Quote each complete argument; continuations only occur between arguments. */
    public static function shell(array $arguments, bool $multiline = true): string
    {
        foreach ($arguments as $argument) {
            if (!is_string($argument) || !preg_match('//u', $argument) || preg_match('/\p{C}/u', $argument)) {
                throw new \RuntimeException('Cannot display a shell command containing invalid text or control characters.');
            }
        }
        $quoted = array_map('escapeshellarg', $arguments);
        if (!$multiline) {
            return implode(' ', $quoted);
        }
        $lines = [];
        $line = '';
        foreach ($quoted as $argument) {
            if ($line !== '' && mb_strwidth($line . ' ' . $argument) > 76) {
                $lines[] = $line;
                $line = '';
            }
            $line .= ($line === '' ? '' : ' ') . $argument;
        }
        $lines[] = $line;
        return implode(" \\\n  ", $lines);
    }

    public function command(string $title, string $description, string $command): void
    {
        $this->heading($title);
        $this->line($description);
        $this->line();
        foreach (explode("\n", $command) as $line) {
            $this->line($line);
        }
    }

    public function status(string $marker, string $message, string $color = '36'): void
    {
        if ($this->quiet) {
            return;
        }
        $label = $this->rich ? "\033[{$color}m" . $marker . "\033[0m" : '[' . $marker . ']';
        fwrite($this->output, $label . ' ' . Terminal::safe($message) . PHP_EOL);
    }

    public function step(string $message, callable $action): mixed
    {
        if (!$this->rich) {
            \WP_CLI::log($message);
            return $action();
        }
        // Keep durable lines in scrollback; a database subprocess has no reliable percentage.
        $this->status('→', $message);
        try {
            $result = $action();
        } catch (\Throwable $failure) {
            $this->status('✕', $message, '31;1');
            throw $failure;
        }
        $this->status('✓', $message, '32');
        return $result;
    }

    public function plan(array $plan): void
    {
        if (!$this->rich || $this->verbose) {
            foreach (Plan::lines($plan) as $line) {
                $this->line($line);
            }
            return;
        }
        $this->heading('Import plan — new website only');
        foreach (['Source' => $plan['source'], 'Destination' => $plan['destination'],
            'Network' => (string) $plan['destination_details']['network_id'],
            'Estimated site ID' => (string) $plan['destination_details']['estimated_site_id'],
            'Site tables' => (string) count($plan['tables'])] as $label => $value) {
            $this->line(str_pad($label, 20) . $value);
        }
        $counts = array_count_values(array_column($plan['users'], 'action'));
        foreach (['create_wordpress_user' => 'New WP accounts', 'add_site_membership' => 'New memberships', 'map_existing_user' => 'Reference only'] as $action => $label) {
            $this->line(str_pad($label, 20) . ($counts[$action] ?? 0));
        }
        $this->line('Numeric user-reference fields: ' . (implode(', ', $plan['user_reference_fields']) ?: 'none'));
        $this->line('Media: ' . $plan['uploads']['files'] . ' files; ' . number_format($plan['uploads']['bytes'] / 1048576, 1) . ' MiB');
        $this->line('Excluded upload directories: ' . (implode(', ', $plan['uploads']['excluded_directories'] ?? []) ?: 'none'));
        $this->status('!', 'External rsync transfer and media verification required.', '33');
        $this->line('Upload destination: ' . $plan['destination_details']['uploads_directory']);
        foreach ($plan['limitations'] as $limitation) {
            $this->line('Note: ' . $limitation);
        }
        $this->line('Use --verbose for individual table mappings and user actions.');
    }

    public function exportPlan(array $plan): void
    {
        $this->heading('Export plan');
        $this->line('Source: ' . $plan['source'] . ' (site ID ' . $plan['site_id'] . ')');
        $this->line('Output: ' . $plan['output']);
        if (!$this->rich || $this->verbose) {
            foreach ($plan['tables'] as $table) {
                $this->line('Table: ' . $table);
            }
        } else {
            $this->line('Site tables: ' . count($plan['tables']) . ' (use --verbose for table names)');
        }
        $this->line('Media: external rsync transfer; inventory and checksums only in ZIP');
        $this->line('Media source: ' . $plan['media_source']);
        $this->line('Excluded upload directories: ' . (implode(', ', $plan['excluded_upload_directories']) ?: 'none'));
    }

    public function failure(string $message, ?int $siteId = null, ?string $runId = null, bool $export = false): void
    {
        if ($this->rich) {
            $error = new self(STDERR, true);
            $error->heading('Migration stopped');
            $error->status('✕', $message, '31;1');
            if ($export) {
                $error->line('Next step: Check the reported cause and restart the export when ready.');
            } else {
                $error->line($siteId === null ? 'Destination website: Not created by this run.' : 'New site ID ' . $siteId . ' may be incomplete.');
                $error->line($siteId === null ? 'Next step: Resolve the reported cause and run preview again.'
                    : 'Next step: Inspect it and delete it manually in Network Admin before retrying; it was not automatically removed. Keep global users.');
            }
            if ($runId !== null) {
                $error->line('Run ID: ' . $runId . '. Read its status before recovery.');
            }
            \WP_CLI::halt(1);
        }
        if ($siteId !== null) {
            $message .= ' New site ID ' . $siteId . ' may be incomplete. Inspect it and delete it manually in Network Admin before retrying; it was not automatically removed.';
        }
        if ($runId !== null) {
            $message .= ' Run ID: ' . $runId . '. Read its status before recovery.';
        }
        \WP_CLI::error(Terminal::safe($message));
    }
}
