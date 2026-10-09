<?php

declare(strict_types=1);

namespace RRZE\CLI\Tests;

use RuntimeException;
use ZipArchive;

/** Mutates disposable packages, optionally preserving valid payload checksums. */
final class PackageFixture
{
    public static function modify(string $path, callable $modify, bool $refreshManifest = true): void
    {
        $zip = self::open($path);
        try {
            $modify($zip);
        } finally {
            self::close($zip);
        }
        if (!$refreshManifest) {
            return;
        }

        // Older libzip versions cannot read replaced entries until the archive
        // has been saved and reopened (ZIP_ER_CHANGED). Hash persisted bytes.
        $zip = self::open($path);
        try {
            $meta = json_decode(self::read($zip, 'site.json'), true);
            if (!is_array($meta)) {
                return; // Some rejection tests deliberately corrupt the metadata.
            }
            $meta['files'] = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if ($entry === false) {
                    throw new RuntimeException('Cannot read a fixture ZIP entry name.');
                }
                if ($entry !== 'site.json' && !str_ends_with($entry, '/')) {
                    $data = self::read($zip, $entry);
                    $meta['files'][$entry] = ['bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
                }
            }
            if (!$zip->addFromString('site.json', json_encode($meta, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Cannot update the fixture manifest.');
            }
        } finally {
            self::close($zip);
        }
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the fixture ZIP.');
        }
        return $zip;
    }

    private static function read(ZipArchive $zip, string $entry): string
    {
        $data = $zip->getFromName($entry);
        if ($data === false) {
            throw new RuntimeException('Cannot read fixture ZIP entry ' . $entry . ': ' . $zip->getStatusString());
        }
        return $data;
    }

    private static function close(ZipArchive $zip): void
    {
        if (!$zip->close()) {
            throw new RuntimeException('Cannot save the fixture ZIP.');
        }
    }
}
