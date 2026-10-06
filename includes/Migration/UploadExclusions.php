<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Explicit directory omissions, relative to this site's upload root; never glob patterns. */
final class UploadExclusions
{
    public static function validate(array $directories): void
    {
        if (!array_is_list($directories) || count($directories) !== count(array_unique($directories, SORT_REGULAR))) {
            throw new RuntimeException('Invalid excluded upload directory list.');
        }
        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory === '' || str_ends_with($directory, '/')) {
                throw new RuntimeException('Excluded upload directories must be nonempty relative paths.');
            }
            Package::path('wp-content/uploads/' . $directory, true);
        }
    }

    public static function parse(string $input, string $root): array
    {
        if ($input === '') {
            return [];
        }
        $directories = array_values(array_unique(array_map(static fn ($value) => rtrim(trim($value), '/'), explode(',', $input))));
        self::validate($directories);
        foreach ($directories as $directory) {
            $path = rtrim($root, '/');
            foreach (explode('/', $directory) as $part) {
                $path .= '/' . $part;
                if (is_link($path) || !is_dir($path)) {
                    throw new RuntimeException('Excluded upload directory is missing or is a link: ' . Terminal::safe($directory));
                }
            }
        }
        sort($directories);
        return $directories;
    }

    public static function contains(string $archivePath, array $directories): bool
    {
        foreach ($directories as $directory) {
            $prefix = 'wp-content/uploads/' . $directory;
            if ($archivePath === $prefix || str_starts_with($archivePath, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
