<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, Package, UploadExclusions, MediaManifest};

final class UploadExclusionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Files::workspace());
        mkdir($this->root . '/uploads/wp-migrate-db', 0700, true);
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testExclusionsRequireExistingDirectoriesAndMatchWholeDirectoryBoundaries(): void
    {
        self::assertSame([], UploadExclusions::parse('', $this->root . '/uploads'));
        $excluded = UploadExclusions::parse('wp-migrate-db/, wp-migrate-db', $this->root . '/uploads');
        self::assertSame(['wp-migrate-db'], $excluded);
        self::assertTrue(UploadExclusions::contains('wp-content/uploads/wp-migrate-db/.htaccess', $excluded));
        self::assertFalse(UploadExclusions::contains('wp-content/uploads/wp-migrate-db-other/image.png', $excluded));
        self::assertFalse(UploadExclusions::contains('tables.sql', $excluded));
    }

    #[DataProvider('invalidPaths')]
    public function testExclusionsCannotEscapeOrExcludeTheWholeRoot(string $value): void
    {
        $this->expectException(RuntimeException::class);
        UploadExclusions::parse($value, $this->root . '/uploads');
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn ($value) => [$value], ['/', '.', '..', '../uploads', '/wp-migrate-db', 'wp-migrate-db/../other', 'missing', '*', 'wp-migrate-db,,']);
    }

    public function testLinkedDirectoryCannotBeSelected(): void
    {
        symlink($this->root, $this->root . '/uploads/link');
        $this->expectExceptionMessage('missing or is a link');
        UploadExclusions::parse('link', $this->root . '/uploads');
    }

    public function testExplicitExclusionPreservesSourceFilesAndNormalMedia(): void
    {
        $uploads = $this->root . '/uploads';
        mkdir($uploads . '/wp-migrate-db-other', 0700);
        file_put_contents($uploads . '/wp-migrate-db/.htaccess', 'Deny from all');
        file_put_contents($uploads . '/wp-migrate-db/index.php', '<?php // Placeholder');
        file_put_contents($uploads . '/wp-migrate-db/backup.sql', 'synthetic private backup');
        file_put_contents($uploads . '/wp-migrate-db-other/photo.png', 'normal media');
        $layout = ['basedir' => $uploads, 'baseurl' => 'https://source.test/uploads'];
        try {
            MediaManifest::capture($layout, []);
            self::fail('An exclusion must never be assumed.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('server configuration', $error->getMessage());
            self::assertStringContainsString('wp-content/uploads/wp-migrate-db/', $error->getMessage());
        }
        $manifest = MediaManifest::capture($layout, ['wp-migrate-db']);
        self::assertSame(['wp-migrate-db'], $manifest['excluded_directories']);
        self::assertSame(['wp-migrate-db-other/photo.png'], array_keys($manifest['files']));
        self::assertSame(hash('sha256', 'normal media'), $manifest['files']['wp-migrate-db-other/photo.png']['sha256']);
        self::assertSame('Deny from all', file_get_contents($uploads . '/wp-migrate-db/.htaccess'));
        self::assertSame('<?php // Placeholder', file_get_contents($uploads . '/wp-migrate-db/index.php'));
        self::assertSame('synthetic private backup', file_get_contents($uploads . '/wp-migrate-db/backup.sql'));
    }
}
