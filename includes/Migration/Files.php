<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

final class Files
{
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
