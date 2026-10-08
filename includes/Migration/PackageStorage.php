<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Private persistent package storage shared by export, import and the wizard. */
final class PackageStorage
{
    private const LIST_LIMIT = 50;
    private const SCAN_LIMIT = 5000;
    private const SCAN_DEPTH = 3;

    public static function root(array $options, bool $create = false): string
    {
        return Run::root($options['run-dir'] ?? (defined('RRZE_MIGRATION_RUN_DIR') ? RRZE_MIGRATION_RUN_DIR : ''),
            [ABSPATH, WP_CONTENT_DIR], $create, !$create);
    }

    public static function exportPath(string $root, string $filename, int $siteId): string
    {
        if ($filename === '' || $filename[0] === '.' || preg_match('~[/\\\\:\x00-\x1f\x7f]~', $filename)) {
            throw new RuntimeException('Provide a ZIP filename without a directory. Export packages are stored in the private migration directory.');
        }
        if (!str_ends_with(strtolower($filename), '.zip')) {
            $filename .= '.zip';
        }
        return $root . '/export-' . gmdate('Ymd-His') . '-site-' . $siteId . '-' . bin2hex(random_bytes(8)) . '/' . $filename;
    }

    public static function reserveExport(string $path): void
    {
        $directory = dirname($path);
        if (is_link($directory) || file_exists($directory) || !mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot reserve a new private export directory. Existing exports are never replaced.');
        }
        try {
            fclose(Files::output($path));
        } catch (\Throwable $error) {
            rmdir($directory);
            throw $error;
        }
    }

    /** Bounded, read-only discovery. File names are candidates, not validated packages. */
    public static function packages(array $options): array
    {
        $root = self::root($options);
        $files = [];
        $incomplete = false;
        $visited = 0;
        $directories = is_dir($root) ? [[$root, 0]] : [];
        $webRoots = array_filter(array_map('realpath', [ABSPATH, WP_CONTENT_DIR]));
        while ($directories) {
            [$directory, $depth] = array_shift($directories);
            $handle = @opendir($directory);
            if ($handle === false) {
                $incomplete = true;
                continue;
            }
            try {
                while (($name = readdir($handle)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    if (++$visited > self::SCAN_LIMIT) {
                        $incomplete = true;
                        break 2;
                    }
                    $path = $directory . '/' . $name;
                    if (is_link($path) || !preg_match('//u', $name) || preg_match('/\p{C}/u', $name)
                        || str_contains($name, '\\') || in_array($path, $webRoots, true)) {
                        continue;
                    }
                    if (is_dir($path)) {
                        if ($depth < self::SCAN_DEPTH) {
                            $directories[] = [$path, $depth + 1];
                        } else {
                            $incomplete = true;
                        }
                        continue;
                    }
                    if (!str_ends_with(strtolower($name), '.zip')) {
                        continue;
                    }
                    $relative = substr($path, strlen($root) + 1);
                    try {
                        self::input($relative, ['run-dir' => $root]);
                    } catch (RuntimeException $error) {
                        continue;
                    }
                    $modified = @filemtime($path);
                    $bytes = @filesize($path);
                    if ($modified !== false && $bytes !== false) {
                        $files[] = ['path' => $relative, 'modified' => $modified, 'bytes' => $bytes];
                    }
                }
            } finally {
                closedir($handle);
            }
        }
        usort($files, static fn ($a, $b) => ($b['modified'] <=> $a['modified']) ?: strcmp($a['path'], $b['path']));
        return ['files' => array_slice($files, 0, self::LIST_LIMIT), 'incomplete' => $incomplete || count($files) > self::LIST_LIMIT];
    }

    public static function input(string $filename, array $options): string
    {
        if ($filename === '' || str_contains($filename, '://') || preg_match('~[\\\\\x00-\x1f\x7f]|(?:^|/)\.\.?(?:/|$)~', $filename)) {
            throw new RuntimeException('Provide a local ZIP path in private storage, without dot segments or control characters.');
        }
        // Discovery and operator review can precede this check by minutes. Resolve the current path.
        clearstatcache(true);
        $root = str_starts_with($filename, '/') ? null : self::root($options);
        $path = $root === null ? $filename : $root . '/' . $filename;
        $resolved = realpath($path);
        if ($resolved === false || is_link($path) || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('Enter a readable local ZIP file in private storage; symbolic links are not supported.');
        }
        foreach ([ABSPATH, WP_CONTENT_DIR] as $webRoot) {
            $webRoot = realpath($webRoot);
            if ($webRoot !== false && str_starts_with($resolved, $webRoot . '/')) {
                throw new RuntimeException('Migration packages inside WordPress or wp-content are not allowed. Move the ZIP to private storage outside the web roots before importing.');
            }
        }
        if ($root !== null && !str_starts_with($resolved, $root . '/')) {
            throw new RuntimeException('The relative package path escapes the private migration directory.');
        }
        return $resolved;
    }
}
