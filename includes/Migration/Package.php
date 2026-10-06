<?php

namespace RRZE\CLI\Migration;

use RuntimeException;
use ZipArchive;

/** Versioned transport envelope. Checksums detect damage, not an untrusted producer. */
final class Package
{
    public const FORMAT = 'rrze-cli-migration';
    public const VERSION = 1;
    public const LIMITS = [
        'archive' => 2147483648, 'total' => 4294967296, 'file' => 536870912,
        'sql' => 67108864, 'csv' => 16777216, 'metadata' => 4194304,
        'entries' => 100000, 'ratio' => 200,
    ];

    public static function path(string $name, bool $directory = false): string
    {
        $path = $directory ? rtrim($name, '/') : $name;
        if ($path === '' || strlen($name) > 1024 || !preg_match('//u', $name)
            || preg_match('~[\x00-\x1f\x7f\\\\:%]|\p{M}~u', $path)) {
            throw new RuntimeException('Unsafe path in the migration package. Path: ' . Terminal::safe($name));
        }
        if (preg_match('~(?:^|/)(?:\.htaccess|\.user\.ini)(?:/|$)|\.(?:php[0-9]*|phtml|phar|phps|cgi|pl|py|sh|shtml)(?:[./]|$)~i', $path)) {
            throw new RuntimeException('Executable files and server configuration are not supported in migration uploads. Path: ' . Terminal::safe($name));
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || trim($part, " .\t") !== $part) {
                throw new RuntimeException('Unsafe path in the migration package. Path: ' . Terminal::safe($name));
            }
        }
        if (!$directory && in_array($path, ['site.json', 'users.csv', 'tables.sql'], true)) {
            return $path;
        }
        if ($directory && in_array($path, ['wp-content', 'wp-content/uploads'], true)) {
            return $path;
        }
        if (!str_starts_with($path, 'wp-content/uploads/')) {
            throw new RuntimeException('Unexpected file or directory in the migration package. Path: ' . Terminal::safe($name));
        }
        return $path;
    }

    public static function metadata(array $meta): void
    {
        if (($meta['format'] ?? null) !== self::FORMAT || ($meta['format_version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported or missing migration format version. Create a new export with this version of rrze-cli.');
        }
        if (!is_string($meta['url'] ?? null) || !is_string($meta['db_prefix'] ?? null)
            || !preg_match('/^[A-Za-z0-9_]+$/D', $meta['db_prefix'])
            || !is_int($meta['blog_id'] ?? null) || $meta['blog_id'] < 1
            || !is_bool($meta['uploads_included'] ?? null) || !is_array($meta['tables'] ?? null)
            || !array_is_list($meta['tables']) || !$meta['tables'] || !is_array($meta['files'] ?? null)) {
            throw new RuntimeException('Invalid migration metadata.');
        }
        SiteAddress::parse($meta['url']);
        $excluded = $meta['excluded_upload_directories'] ?? [];
        if (!is_array($excluded) || (!$meta['uploads_included'] && $excluded)) {
            throw new RuntimeException('Invalid excluded upload directory metadata.');
        }
        UploadExclusions::validate($excluded);
        foreach ($meta['tables'] as $table) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                throw new RuntimeException('Invalid table manifest.');
            }
        }
        if (count($meta['tables']) !== count(array_unique($meta['tables']))) {
            throw new RuntimeException('Duplicate table in the manifest.');
        }
        foreach ($meta['files'] as $name => $file) {
            if (!is_string($name) || self::path($name) === 'site.json' || !is_array($file)
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new RuntimeException('Invalid file manifest.');
            }
            if (!$meta['uploads_included'] && str_starts_with($name, 'wp-content/uploads/')) {
                throw new RuntimeException('Upload manifest contradicts the package metadata.');
            }
            if (UploadExclusions::contains($name, $excluded)) {
                throw new RuntimeException('Upload manifest contains a file declared as excluded.');
            }
        }
    }

    /** Validate every entry before extracting; bound actual streamed bytes as well as ZIP headers. */
    public static function read(string $filename, ?string $workspace, array $limits = []): array
    {
        $limits += self::LIMITS;
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Migration packages require the PHP zip extension.');
        }
        if (!is_file($filename) || !is_readable($filename) || filesize($filename) > $limits['archive']) {
            throw new RuntimeException('The package is unreadable or exceeds the archive size limit.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filename, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The provided file is not a readable ZIP package.');
        }
        try {
            if ($zip->numFiles > $limits['entries']) {
                throw new RuntimeException('The package exceeds the entry count limit.');
            }
            Files::memory($zip->numFiles * 4096 + $limits['metadata'] * 8);
            $entries = $seen = $files = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException('Cannot read the package directory.');
                }
                $directory = str_ends_with($stat['name'], '/');
                $name = self::path($stat['name'], $directory);
                $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                if (isset($seen[$key])) {
                    throw new RuntimeException('Duplicate or case-colliding path in the migration package.');
                }
                $seen[$key] = $directory;
                $zip->getExternalAttributesIndex($index, $os, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (($os === ZipArchive::OPSYS_UNIX && !in_array($type, [0, $directory ? 0040000 : 0100000], true))
                    || ($stat['encryption_method'] ?? 0) !== 0 || !in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                    throw new RuntimeException('Links, special files, encryption or unsupported compression in the migration package.');
                }
                $limit = match ($name) {
                    'site.json' => $limits['metadata'], 'users.csv' => $limits['csv'], 'tables.sql' => $limits['sql'], default => $limits['file'],
                };
                $total += $stat['size'];
                if ($stat['size'] > $limit || $total > $limits['total']
                    || ($stat['size'] > 1048576 && $stat['size'] > max(1, $stat['comp_size']) * $limits['ratio'])
                    || ($directory && $stat['size'] !== 0)) {
                    throw new RuntimeException('The package exceeds an unpacked size or compression ratio limit.');
                }
                if (!$directory) {
                    $entries[$name] = $stat;
                }
            }
            foreach ($seen as $name => $directory) {
                $parent = dirname($name);
                while ($parent !== '.') {
                    if (isset($seen[$parent]) && !$seen[$parent]) {
                        throw new RuntimeException('A package file is also used as a directory.');
                    }
                    $parent = dirname($parent);
                }
            }
            if (array_diff(['site.json', 'users.csv', 'tables.sql'], array_keys($entries))) {
                throw new RuntimeException('Missing required site.json, users.csv or tables.sql.');
            }
            $json = $zip->getFromName('site.json', $limits['metadata'] + 1);
            $meta = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($meta)) {
                throw new RuntimeException('Invalid migration metadata.');
            }
            self::metadata($meta);
            $expected = array_diff(array_keys($entries), ['site.json']);
            if (array_diff($expected, array_keys($meta['files'])) || array_diff(array_keys($meta['files']), $expected)) {
                throw new RuntimeException('The file manifest does not match the archive contents.');
            }
            if ($workspace !== null) {
                Files::capacity($workspace, $total + $entries['tables.sql']['size'] * 2 + 16777216);
            }
            foreach ($entries as $name => $stat) {
                if ($name !== 'site.json' && $meta['files'][$name]['bytes'] !== $stat['size']) {
                    throw new RuntimeException('File size does not match the package manifest.');
                }
                $input = $zip->getStream($name);
                if ($input === false) {
                    throw new RuntimeException('Cannot read a package entry.');
                }
                $output = null;
                try {
                    if ($workspace !== null) {
                        $file = $workspace . '/' . $name;
                        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
                            throw new RuntimeException('Cannot create a private package directory.');
                        }
                        $output = Files::output($file);
                    }
                    $hash = hash_init('sha256');
                    $crc = hash_init('crc32b');
                    $bytes = 0;
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($input))) {
                            throw new RuntimeException('Cannot completely read a package entry.');
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > $stat['size']) {
                            throw new RuntimeException('Expanded entry exceeds its declared size.');
                        }
                        hash_update($hash, $chunk);
                        hash_update($crc, $chunk);
                        if ($output !== null && fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Cannot completely extract a package entry.');
                        }
                    }
                    $digest = hash_final($hash);
                    if ($bytes !== $stat['size'] || hash_final($crc) !== sprintf('%08x', $stat['crc'])
                        || ($name !== 'site.json' && !hash_equals($meta['files'][$name]['sha256'], $digest))) {
                        throw new RuntimeException('Package checksum or size mismatch. Create or transfer the export again.');
                    }
                    $files[$name] = ['bytes' => $bytes, 'sha256' => $digest];
                } finally {
                    fclose($input);
                    if ($output !== null) {
                        fclose($output);
                    }
                }
            }
            return ['meta' => $meta, 'files' => $files, 'bytes' => $total];
        } finally {
            $zip->close();
        }
    }

    public static function write(string $output, array $paths, array $meta, string $workspace): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Migration packages require the PHP zip extension.');
        }
        $excluded = $meta['excluded_upload_directories'] ?? [];
        UploadExclusions::validate($excluded);
        $files = [];
        foreach ($paths as $name => $path) {
            if (is_link($path)) {
                throw new RuntimeException('Symbolic links cannot be exported.');
            }
            if (is_dir($path)) {
                $entries = new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    static fn ($entry) => !UploadExclusions::contains($name . '/' . substr($entry->getPathname(), strlen($path) + 1), $excluded)
                );
                foreach (new \RecursiveIteratorIterator($entries) as $entry) {
                    if ($entry->isLink() || !$entry->isFile()) {
                        throw new RuntimeException('Links and special files cannot be exported.');
                    }
                    $files[$name . '/' . substr($entry->getPathname(), strlen($path) + 1)] = $entry->getPathname();
                }
            } else {
                $files[$name] = $path;
            }
        }
        ksort($files);
        $meta['format'] = self::FORMAT;
        $meta['format_version'] = self::VERSION;
        $meta['files'] = [];
        if (count($files) + 1 > self::LIMITS['entries']) {
            throw new RuntimeException('The export exceeds the entry count limit.');
        }
        $total = 0;
        foreach ($files as $name => $path) {
            self::path($name);
            $bytes = filesize($path);
            $total += $bytes;
            $limit = match ($name) { 'tables.sql' => self::LIMITS['sql'], 'users.csv' => self::LIMITS['csv'], default => self::LIMITS['file'] };
            if ($bytes === false || $bytes > $limit || $total > self::LIMITS['total']) {
                throw new RuntimeException('The export exceeds a supported file size limit.');
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Could not read a source file for the manifest.');
            }
            $meta['files'][$name] = ['bytes' => filesize($path), 'sha256' => $hash];
        }
        self::metadata($meta);
        Files::write($workspace . '/site.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $files['site.json'] = $workspace . '/site.json';
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the migration archive.');
        }
        try {
            foreach ($files as $name => $path) {
                if (!$zip->addFile($path, $name)) {
                    throw new RuntimeException('Cannot add a file to the migration archive.');
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Cannot completely write the migration archive.');
            }
        }
        // Detect source changes between hashing and ZIP creation; never publish an inconsistent package.
        self::read($output, null);
    }
}
