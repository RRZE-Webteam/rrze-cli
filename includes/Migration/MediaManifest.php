<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** File inventory for an external transfer. Media bytes never enter the ZIP. */
final class MediaManifest
{
    public const MAX_BYTES = 67108864;
    public const MAX_FILES = 100000;

    public static function absolute(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '' || !str_starts_with($path, '/') || !preg_match('//u', $path)
            || preg_match('~[\x00-\x1f\x7f\\\\]|//|(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new RuntimeException('Media directories must be unambiguous absolute local paths.');
        }
        return $path;
    }

    /** Check every existing ancestor, including the root itself, without following links. */
    public static function directory(string $path): void
    {
        $path = self::absolute($path);
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $current .= '/' . $part;
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new RuntimeException('A media directory or parent is a link or is not a directory.');
            }
        }
    }

    public static function capture(array $layout, array $excluded, array $protected = []): array
    {
        $root = self::absolute($layout['basedir']);
        self::directory($root);
        $manifest = ['version' => 1, 'transport' => 'rsync', 'source_directory' => $root,
            'source_baseurl' => rtrim(SiteAddress::parse($layout['baseurl'])['url'], '/'),
            'excluded_directories' => $excluded, 'protected_directories' => $protected, 'files' => []];
        if (is_dir($root)) {
            $entries = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn ($entry) => !UploadExclusions::contains('wp-content/uploads/' . substr($entry->getPathname(), strlen($root) + 1), $excluded)
            );
            foreach (new \RecursiveIteratorIterator($entries) as $entry) {
                $name = substr($entry->getPathname(), strlen($root) + 1);
                Package::path('wp-content/uploads/' . $name);
                if ($entry->isLink() || !$entry->isFile()) {
                    throw new RuntimeException('Links and special files are not supported in the media inventory.');
                }
                if (count($manifest['files']) >= self::MAX_FILES) {
                    throw new RuntimeException('The media inventory exceeds the file count limit.');
                }
                if (count($manifest['files']) % 1000 === 0) {
                    Files::memory(8388608);
                }
                $manifest['files'][$name] = self::fingerprint($entry->getPathname());
            }
        } elseif (file_exists($root)) {
            throw new RuntimeException('Cannot read the source media directory.');
        }
        ksort($manifest['files']);
        self::validate($manifest);
        return $manifest;
    }

    private static function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $before = stat($path);
        $hash = hash_file('sha256', $path);
        clearstatcache(true, $path);
        $after = stat($path);
        if (!$before || !$after || $hash === false || array_intersect_key($before, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime', 'mode'])) !== array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime', 'mode']))) {
            throw new RuntimeException('A media file is unreadable or changed while being inventoried. Freeze source writes and export again.');
        }
        return ['bytes' => $after['size'], 'sha256' => $hash];
    }

    public static function read(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('The media inventory exceeds its size limit.');
        }
        $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Invalid media inventory.');
        }
        self::validate($manifest);
        return $manifest;
    }

    public static function validate(array $manifest): void
    {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['transport'] ?? null) !== 'rsync'
            || !is_string($manifest['source_directory'] ?? null) || !is_string($manifest['source_baseurl'] ?? null)
            || !is_array($manifest['files'] ?? null) || count($manifest['files']) > self::MAX_FILES
            || !is_array($manifest['excluded_directories'] ?? null) || !is_array($manifest['protected_directories'] ?? null)) {
            throw new RuntimeException('Invalid media inventory.');
        }
        self::absolute($manifest['source_directory']);
        $url = SiteAddress::parse($manifest['source_baseurl']);
        if ($url['path'] === '/') {
            throw new RuntimeException('A media URL must have a dedicated directory path.');
        }
        UploadExclusions::validate($manifest['excluded_directories']);
        UploadExclusions::validate($manifest['protected_directories']);
        $seen = [];
        foreach ($manifest['files'] as $name => $entry) {
            if (!is_string($name) || !is_array($entry) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0
                || !is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])) {
                throw new RuntimeException('Invalid media file fingerprint.');
            }
            Package::path('wp-content/uploads/' . $name);
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key]) || UploadExclusions::contains('wp-content/uploads/' . $name, $manifest['excluded_directories'])) {
                throw new RuntimeException('Duplicate, case-colliding or excluded file in the media inventory.');
            }
            $seen[$key] = true;
        }
        foreach ($seen as $name => $_) {
            for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($seen[$parent])) {
                    throw new RuntimeException('A media file is also used as a directory.');
                }
            }
        }
    }

    public static function fileList(array $manifest): string
    {
        return $manifest['files'] ? implode("\0", array_keys($manifest['files'])) . "\0" : '';
    }

    public static function verify(array $manifest, string $directory): array
    {
        self::directory($directory);
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $name = substr($entry->getPathname(), strlen($directory) + 1);
                if ($entry->isLink() || (!$entry->isDir() && (!isset($manifest['files'][$name]) || !$entry->isFile()))) {
                    throw new RuntimeException('Unexpected or unsafe file in the media destination: ' . Terminal::safe($name));
                }
            }
        }
        $bytes = 0;
        foreach ($manifest['files'] as $name => $entry) {
            $path = $directory . '/' . $name;
            self::directory(dirname($path));
            if (is_link($path) || !is_file($path) || self::fingerprint($path) !== $entry) {
                throw new RuntimeException('Media verification failed (missing, changed or unsafe file): ' . Terminal::safe($name));
            }
            $bytes += $entry['bytes'];
        }
        return ['files' => count($manifest['files']), 'bytes' => $bytes];
    }
}
