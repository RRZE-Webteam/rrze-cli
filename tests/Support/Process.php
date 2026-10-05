<?php

declare(strict_types=1);

namespace RRZE\CLI\Tests;

use RuntimeException;

final class Process
{
    /** @return array{code: int, stdout: string, stderr: string} */
    public static function run(array $command, string $directory, ?array $environment = null, int $timeout = 120): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start test subprocess.');
        }
        fclose($pipes[0]);
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
}
