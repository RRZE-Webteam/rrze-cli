<?php

// Standalone real-terminal fixture: no WordPress, database or site data.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use RRZE\CLI\Migration\Terminal;

function ttyMode(): string
{
    $process = proc_open(['stty', '-g'], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    $mode = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $mode = trim($mode);
    // macOS sets the transient PENDIN state when restoring a canonical terminal.
    // Compare configuration flags, not the kernel's pending-input state bit.
    if (PHP_OS_FAMILY === 'Darwin') {
        $mode = preg_replace_callback('/lflag=([a-f0-9]+)/', static fn ($m) => 'lflag=' . dechex(hexdec($m[1]) & ~0x20000000), $mode);
    }
    return $mode;
}

$before = ttyMode();
$code = 0;
try {
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static fn () => throw new RuntimeException('cancelled by a signal'));
    }
    $terminal = new Terminal(STDIN, STDOUT, true);
    $answer = match ($argv[1]) {
        'confirm' => $terminal->confirm('Execute migration'),
        'select' => $terminal->select('Choose package', ['manual' => 'Manual entry', 'a' => "<fg=red>file\033[2J.zip</>", 'b' => 'Second package'], 'manual'),
        'identity' => $terminal->identity('Destination', 'https://target.test/site/'),
        'text' => $terminal->ask('Filename', 'website.zip'),
        'validate' => $terminal->ask('Mode', 'preview', static function ($value) {
            if ($value !== 'preview') {
                throw new RuntimeException('Invalid mode');
            }
        }),
    };
    echo '\nANSWER=' . json_encode($answer, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    echo '\nERROR=' . $error->getMessage() . PHP_EOL;
    $code = 1;
} finally {
    $after = ttyMode();
    echo 'TTY_RESTORED=' . ($before === $after ? 'yes' : 'no: ' . $before . ' -> ' . $after) . PHP_EOL;
}
exit($code);
