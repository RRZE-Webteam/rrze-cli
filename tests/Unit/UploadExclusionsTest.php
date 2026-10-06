<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, Package, UploadExclusions};

final class UploadExclusionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Files::workspace();
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
        $paths = ['tables.sql' => $this->root . '/tables.sql', 'users.csv' => $this->root . '/users.csv', 'wp-content/uploads' => $uploads];
        file_put_contents($paths['tables.sql'], 'controlled SQL');
        file_put_contents($paths['users.csv'], 'synthetic users');
        $meta = ['url' => 'https://source.test/', 'blog_id' => 2, 'db_prefix' => 'wp_2_', 'tables' => ['wp_2_posts'], 'uploads_included' => true];
        $zip = $this->root . '/export.zip';
        file_put_contents($zip, '');
        try {
            Package::write($zip, $paths, $meta, $this->root);
            self::fail('An exclusion must never be assumed.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('server configuration', $error->getMessage());
            self::assertStringContainsString('wp-content/uploads/wp-migrate-db/.htaccess', $error->getMessage());
        }
        $meta['excluded_upload_directories'] = ['wp-migrate-db'];
        Package::write($zip, $paths, $meta, $this->root);
        mkdir($this->root . '/extract', 0700);
        $package = Package::read($zip, $this->root . '/extract');
        self::assertSame(['wp-migrate-db'], $package['meta']['excluded_upload_directories']);
        self::assertCount(4, $package['files']);
        self::assertSame('normal media', file_get_contents($this->root . '/extract/wp-content/uploads/wp-migrate-db-other/photo.png'));
        self::assertDirectoryDoesNotExist($this->root . '/extract/wp-content/uploads/wp-migrate-db');
        self::assertSame('Deny from all', file_get_contents($uploads . '/wp-migrate-db/.htaccess'));
        self::assertSame('<?php // Placeholder', file_get_contents($uploads . '/wp-migrate-db/index.php'));
        self::assertSame('synthetic private backup', file_get_contents($uploads . '/wp-migrate-db/backup.sql'));
    }
}
