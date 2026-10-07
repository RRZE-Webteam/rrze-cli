<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Private persistent package storage shared by export, import and the wizard. */
final class PackageStorage
{
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

    public static function input(string $filename, array $options): string
    {
        if ($filename === '' || str_contains($filename, '://') || preg_match('~[\\\\\x00-\x1f\x7f]|(?:^|/)\.\.?(?:/|$)~', $filename)) {
            throw new RuntimeException('Provide a local ZIP path in private storage, without dot segments or control characters.');
        }
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
