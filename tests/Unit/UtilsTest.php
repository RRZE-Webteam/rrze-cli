<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Utils;

final class UtilsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rrze-cli-unit-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
        unset($GLOBALS['wpdb']);
    }

    public function testArchiveRoundTripPreservesNestedNamesAndBinaryContents(): void
    {
        mkdir($this->directory . '/uploads');
        mkdir($this->directory . '/uploads/2026');
        $bytes = "\x00\xff\x01Migration\n";
        file_put_contents($this->directory . '/uploads/2026/Über uns.bin', $bytes);
        file_put_contents($this->directory . '/meta.json', '{"name":"Test"}');

        $archive = $this->directory . '/site with spaces.zip';
        Utils::zip($archive, [
            'meta.json' => $this->directory . '/meta.json',
            'wp-content/uploads' => $this->directory . '/uploads',
        ]);
        self::assertTrue(Utils::is_zip_file($archive));
        Utils::extract($archive, $this->directory . '/extracted');

        self::assertSame($bytes, file_get_contents($this->directory . '/extracted/wp-content/uploads/2026/Über uns.bin'));
        self::assertSame('{"name":"Test"}', file_get_contents($this->directory . '/extracted/meta.json'));
    }

    public function testPlainTextIsNotRecognizedAsAnArchive(): void
    {
        $file = $this->directory . '/not-an-archive.zip';
        file_put_contents($file, 'This is not a zip archive.');
        self::assertFalse(Utils::is_zip_file($file));
    }

    public function testMovingNestedUploadsPreservesTheirContents(): void
    {
        mkdir($this->directory . '/source');
        mkdir($this->directory . '/source/nested');
        file_put_contents($this->directory . '/source/nested/media.txt', 'fixture media');
        Utils::move_folder($this->directory . '/source', $this->directory . '/target');
        self::assertSame('fixture media', file_get_contents($this->directory . '/target/nested/media.txt'));
        self::assertFileDoesNotExist($this->directory . '/source/nested/media.txt');
    }

    public function testSqlTransactionWrapperPreservesDumpBytes(): void
    {
        // This checks file handling only. DDL in a dump is not made atomic by this wrapper.
        $dump = "INSERT INTO `custom_posts` VALUES (1, 'Über uns');\n";
        $file = $this->directory . '/dump.sql';
        file_put_contents($file, $dump);
        Utils::addTransaction($file);
        self::assertSame('START TRANSACTION;' . PHP_EOL . $dump . 'COMMIT;', file_get_contents($file));
    }

    #[DataProvider('prefixes')]
    public function testBlogTablePrefixesRespectTheInstallationPrefix(int $blogId, string $base, string $expected): void
    {
        $GLOBALS['wpdb'] = (object) ['base_prefix' => $base, 'prefix' => $base];
        self::assertSame($expected, Utils::get_db_prefix($blogId));
    }

    public static function prefixes(): iterable
    {
        yield 'main site' => [1, 'wp_', 'wp_'];
        yield 'subsite' => [2, 'wp_', 'wp_2_'];
        yield 'custom prefix' => [42, 'rrze_test_', 'rrze_test_42_'];
    }
}
