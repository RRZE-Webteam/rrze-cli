<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

final class Files
{
    public static function memory(int $required): void
    {
        $limit = ini_get('memory_limit');
        if ($limit !== false && $limit !== '-1' && $required > ini_parse_quantity($limit) - memory_get_usage(true)) {
            throw new RuntimeException('Insufficient PHP memory budget for migration preflight.');
        }
    }

    /** Check the nearest existing parent without creating directories or probe files. */
    public static function capacity(string $path, int $required): array
    {
        $parent = $path;
        while (!file_exists($parent) && !is_link($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                throw new RuntimeException('Cannot find the destination filesystem.');
            }
            $parent = $next;
        }
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('The migration filesystem is not writable.');
        }
        $available = disk_free_space($parent);
        if ($available === false || $available < $required) {
            throw new RuntimeException('Insufficient or unknown free disk space for migration.');
        }
        return ['required_bytes' => $required, 'available_bytes' => (int) $available];
    }

    public static function workspace(): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/rrze-migration-' . bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create a private migration workspace.');
        }
        return $path;
    }

    public static function write(string $file, string $contents): void
    {
        if (file_put_contents($file, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not completely write a migration file.');
        }
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Invalid migration workspace for cleanup.');
        }
        foreach (new \FilesystemIterator($directory) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::remove($item->getPathname());
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Could not remove a temporary migration file.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Could not remove the migration workspace.');
        }
    }

    public static function output(string $path)
    {
        $handle = @fopen($path, 'x+b');
        if (!$handle) {
            throw new RuntimeException('The output file already exists or cannot be created. Choose a new filename.');
        }
        chmod($path, 0600);
        return $handle;
    }
}
