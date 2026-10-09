<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Files;
use RRZE\CLI\Tests\PackageFixture;

require_once dirname(__DIR__) . '/Support/PackageFixture.php';

final class PackageFixtureTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = Files::workspace();
        $this->path = $this->directory . '/fixture.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path, ZipArchive::CREATE));
        $zip->addFromString('site.json', json_encode(['format_version' => 2, 'files' => ['intentional-checksum' => 'unchanged']]));
        $zip->addFromString('users.csv', 'before');
        $zip->addFromString('tables.sql', 'original SQL');
        self::assertTrue($zip->close());
    }

    protected function tearDown(): void
    {
        Files::remove($this->directory);
    }

    public function testChecksumsCoverSavedReplacementsAndMetadataEdits(): void
    {
        PackageFixture::modify($this->path, static function (ZipArchive $zip): void {
            $meta = json_decode($zip->getFromName('site.json'), true);
            $meta['format_version'] = 1; // Invalid package versions must remain invalid.
            $zip->addFromString('site.json', json_encode($meta));
            $zip->addFromString('users.csv', 'replacement Grüße');
            $zip->deleteName('tables.sql');
            $zip->addFromString('media.json', '{}');
        });
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path));
        try {
            $meta = json_decode($zip->getFromName('site.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $meta['format_version']);
            self::assertSame('replacement Grüße', $zip->getFromName('users.csv'));
            self::assertSame(['users.csv', 'media.json'], array_keys($meta['files']));
            foreach ($meta['files'] as $name => $entry) {
                $bytes = $zip->getFromName($name);
                self::assertSame(['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $entry);
            }
        } finally {
            $zip->close();
        }
    }

    public function testDeliberatelyInvalidMetadataAndChecksumsStayInvalid(): void
    {
        PackageFixture::modify($this->path, static fn ($zip) => $zip->addFromString('users.csv', 'tampered'), false);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path));
        self::assertSame(['intentional-checksum' => 'unchanged'], json_decode($zip->getFromName('site.json'), true)['files']);
        $zip->close();
        PackageFixture::modify($this->path, static fn ($zip) => $zip->addFromString('site.json', '{broken'));
        self::assertTrue($zip->open($this->path));
        self::assertSame('{broken', $zip->getFromName('site.json'));
        $zip->close();
    }
}
