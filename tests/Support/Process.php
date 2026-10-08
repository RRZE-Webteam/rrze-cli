<?php

declare(strict_types=1);

namespace RRZE\CLI\Tests;

use RuntimeException;

final class Process
{
    /** @return array{code: int, stdout: string, stderr: string} */
    public static function run(array $command, string $directory, ?array $environment = null, int $timeout = 120): array
    {
        return self::finish(self::start($command, $directory, $environment), $timeout);
    }

    /** Start an owned fixture process for concurrency and interruption tests. */
    public static function start(array $command, string $directory, ?array $environment = null): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start test subprocess.');
        }
        fclose($pipes[0]);
        return compact('process', 'stdout', 'stderr', 'command');
    }

    public static function finish(array $running, int $timeout = 120): array
    {
        ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr, 'command' => $command] = $running;
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Test subprocess timed out: ' . implode(' ', $command));
                }
                usleep(20000);
            } while (true);
            rewind($stdout);
            rewind($stderr);
            return [
                'code' => $status['exitcode'],
                'stdout' => stream_get_contents($stdout),
                'stderr' => stream_get_contents($stderr),
            ];
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            fclose($stdout);
            fclose($stderr);
        }
    }

    public static function checked(array $command, string $directory, ?array $environment = null, int $timeout = 120): string
    {
        $result = self::run($command, $directory, $environment, $timeout);
        if ($result['code'] !== 0) {
            throw new RuntimeException(implode(' ', $command) . "\n" . $result['stdout'] . $result['stderr']);
        }
        return $result['stdout'];
    }

    /** Drive real Unix pseudo-terminals; each answer follows an observed prompt. */
    public static function terminal(array $command, string $directory, array $environment, array $dialogue): array
    {
        $process = proc_open($command, [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start interactive test subprocess.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        // proc_open connects these descriptors to one PTY; stdout/stderr are merged.
        $output = '';
        $next = 0;
        $offset = 0;
        $deadline = microtime(true) + 120;
        try {
            do {
                foreach ($pipes as $index => $pipe) {
                    // A closed PTY reports EIO on some supported Unix platforms.
                    $chunk = @stream_get_contents($pipe);
                    if ($chunk !== false) {
                        $output .= $chunk;
                    }
                }
                if (isset($dialogue[$next])) {
                    [$prompt, $answer] = $dialogue[$next];
                    $position = strpos($output, $prompt, $offset);
                    if ($position !== false) {
                        $offset = $position + strlen($prompt);
                        if (is_callable($answer)) {
                            $answer = $answer($process);
                        }
                        if (is_array($answer)) {
                            // Raw key chunks for interactive renderers; Enter is explicit.
                            foreach ($answer as $keys) {
                                fwrite($pipes[0], $keys);
                                usleep(75000);
                            }
                        } elseif ($answer !== null) {
                            fwrite($pipes[0], $answer . "\n");
                        }
                        $next++;
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Interactive test timed out at answer ' . $next . ': ' . $output);
                }
                usleep(20000);
            } while (true);
            foreach ($pipes as $index => $pipe) {
                $output .= @stream_get_contents($pipe) ?: '';
            }
            return ['code' => $status['exitcode'], 'stdout' => $output, 'stderr' => '', 'answers' => $next];
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
