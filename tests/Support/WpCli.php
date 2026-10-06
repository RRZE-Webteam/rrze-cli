<?php

declare(strict_types=1);

namespace RRZE\CLI\Tests;

use RuntimeException;

final class WpCli
{
    public static function findExecutable(string $name, string $searchPath): string
    {
        foreach (str_contains($name, '/') ? [''] : explode(PATH_SEPARATOR, $searchPath) as $directory) {
            $candidate = $directory === '' ? $name : $directory . '/' . $name;
            if (!is_file($candidate) || !is_executable($candidate) || !is_readable($candidate)) {
                continue;
            }
            // Composer prepends vendor/bin to PATH. Its wp shell proxy is not a
            // PHP entry point: passing it to PHP prints its source with exit 0.
            $header = file_get_contents($candidate, false, null, 0, 256);
            if (preg_match('~\A(?:\#![^\r\n]*\r?\n)?\s*<\?php\b~', $header)) {
                return realpath($candidate);
            }
        }
        throw new RuntimeException('No WP-CLI PHP entry point found. Set RRZE_TEST_WP_CLI to an executable WP-CLI PHAR or PHP entry point, not a shell wrapper.');
    }
}
