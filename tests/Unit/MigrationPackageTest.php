<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, Package, Privileges};

final class MigrationPackageTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Files::workspace();
    }

    protected function tearDown(): void
    {
        Files::remove($this->workspace);
    }

    private function archive(array $entries = [], ?callable $changeMeta = null): string
    {
        $entries += ['media.json' => json_encode(['version' => 1, 'transport' => 'rsync', 'source_directory' => '/source/uploads', 'source_baseurl' => 'https://source.test/uploads', 'excluded_directories' => [], 'protected_directories' => [], 'files' => []]), 'users.csv' => 'ID,user_login,user_email,role', 'tables.sql' => 'controlled SQL fixture'];
        $meta = ['format' => Package::FORMAT, 'format_version' => Package::VERSION, 'url' => 'https://source.test/site/',
            'db_prefix' => 'wp_2_', 'blog_id' => 2, 'tables' => ['wp_2_posts'], 'media_transport' => 'rsync', 'files' => []];
        foreach ($entries as $name => $data) {
            $meta['files'][$name] = ['bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
        }
        if ($changeMeta) {
            $meta = $changeMeta($meta);
        }
        $zip = new ZipArchive();
        $path = $this->workspace . '/fixture.zip';
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries + ['site.json' => json_encode($meta)] as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();
        return $path;
    }

    public function testVersionedPackageIsStreamedIntoPrivateFilesAndVerified(): void
    {
        $zip = $this->archive();
        mkdir($this->workspace . '/extract', 0700);
        $package = Package::read($zip, $this->workspace . '/extract');
        self::assertSame(2, $package['meta']['format_version']);
        self::assertFileExists($this->workspace . '/extract/media.json');
        self::assertDirectoryDoesNotExist($this->workspace . '/extract/wp-content');
        self::assertSame(0600, fileperms($this->workspace . '/extract/tables.sql') & 0777);
        self::assertSame('rsync', $package['media']['transport']);
    }

    public function testWriterPublishesOnlyDataAndInventoryAndRejectsMediaPayloads(): void
    {
        $package = Package::read($this->archive(), null);
        $paths = [];
        foreach (['tables.sql' => 'SQL fixture', 'users.csv' => 'CSV fixture', 'media.json' => json_encode($package['media'])] as $name => $contents) {
            $paths[$name] = $this->workspace . '/' . $name;
            file_put_contents($paths[$name], $contents);
        }
        $output = $this->workspace . '/new.zip';
        fclose(Files::output($output));
        Package::write($output, $paths, $package['meta'], $this->workspace);
        $written = Package::read($output, null);
        self::assertCount(4, $written['files']);
        self::assertSame($package['media'], $written['media']);
        $paths['wp-content/uploads/photo.jpg'] = $paths['users.csv'];
        $this->expectExceptionMessage('Media bytes are transferred externally');
        Package::write($output, $paths, $package['meta'], $this->workspace);
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeOrUnexpectedPathsAreRejectedBeforeExtraction(string $path): void
    {
        $archive = $this->archive([$path => 'blocked']);
        $this->expectException(RuntimeException::class);
        Package::read($archive, null);
    }

    public static function unsafePaths(): array
    {
        return array_map(static fn ($path) => [$path], [
            '../outside.txt', '/absolute', 'C:/drive', 'wp-content/uploads/../escape',
            'wp-content/uploads//double', 'wp-content/uploads/./dot', 'wp-content/uploads/a\\b',
            'wp-content/uploads/a%2fb', 'wp-content/uploads/trailing.', 'wp-content/uploads/file:stream',
            'wp-content/uploads/run.php', 'wp-content/uploads/run.PHP.jpg', 'wp-content/uploads/.htaccess',
            'wp-content/uploads/.user.ini', 'wp-content/plugins/x.txt', 'unknown.json',
        ]);
    }

    #[DataProvider('invalidMetadata')]
    public function testUnsupportedOrInconsistentMetadataIsRejected(array $changes): void
    {
        $archive = $this->archive([], static fn ($meta) => array_replace($meta, $changes));
        $this->expectException(RuntimeException::class);
        Package::read($archive, null);
    }

    public static function invalidMetadata(): array
    {
        return [[['format_version' => 99]], [['format_version' => null]], [['format' => 'foreign']],
            [['blog_id' => '2']], [['tables' => ['wp_2_posts', 'wp_2_posts']]], [['tables' => [1]]],
            [['files' => []]], [['db_prefix' => '../']], [['media_transport' => 'zip']], [['format_version' => 1]]];
    }

    public function testChecksumMismatchIsRejected(): void
    {
        $file = $this->archive([], static function ($meta) {
            $meta['files']['tables.sql']['sha256'] = str_repeat('0', 64);
            return $meta;
        });
        $this->expectExceptionMessage('checksum');
        Package::read($file, null);
    }

    public function testDeclaredExclusionCannotHideAnIncludedFile(): void
    {
        $file = $this->archive(['wp-content/uploads/backups/file.jpg' => 'data'], static function ($meta) {
            $meta['excluded_upload_directories'] = ['backups'];
            return $meta;
        });
        $this->expectExceptionMessage('Media payloads are no longer supported');
        Package::read($file, null);
    }

    public function testInvalidExclusionMetadataIsRejected(): void
    {
        $file = $this->archive([], static function ($meta) {
            $meta['excluded_upload_directories'] = ['../outside'];
            return $meta;
        });
        $this->expectExceptionMessage('Unsafe path');
        Package::read($file, null);
    }

    public function testUnsafeFilenameIsReportedWithoutRawTerminalControlCharacters(): void
    {
        try {
            Package::path("wp-content/uploads/unsafe\033[2J.jpg");
            self::fail('Control characters must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Path: wp-content/uploads/unsafe', $error->getMessage());
            self::assertStringNotContainsString("\033", $error->getMessage());
        }
    }

    #[DataProvider('limits')]
    public function testResourceLimitsAreAppliedBeforeExtraction(array $limits): void
    {
        $file = $this->archive(['wp-content/uploads/large.txt' => str_repeat('x', 1100000)]);
        $this->expectException(RuntimeException::class);
        Package::read($file, null, $limits);
    }

    public static function limits(): array
    {
        return [[['archive' => 1]], [['entries' => 2]], [['total' => 100]], [['sql' => 1]],
            [['csv' => 1]], [['metadata' => 1]], [['file' => 100]], [['ratio' => 2]]];
    }

    #[DataProvider('pathConflicts')]
    public function testAmbiguousPathsCannotOverwriteEachOther(array $entries): void
    {
        $this->expectException(RuntimeException::class);
        Package::read($this->archive($entries), null);
    }

    public static function pathConflicts(): array
    {
        return [[['wp-content/uploads/X.jpg' => 'a', 'wp-content/uploads/x.jpg' => 'b']],
            [['wp-content/uploads/parent' => 'a', 'wp-content/uploads/parent/child' => 'b']]];
    }

    public function testSymlinkEntryIsRejected(): void
    {
        $file = $this->archive(['wp-content/uploads/link' => '/etc/passwd']);
        $zip = new ZipArchive();
        $zip->open($file);
        $zip->setExternalAttributesName('wp-content/uploads/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectExceptionMessage('Links');
        Package::read($file, null);
    }

    public function testEncryptedEntryIsRejected(): void
    {
        $file = $this->archive();
        $zip = new ZipArchive();
        $zip->open($file);
        $zip->setEncryptionName('tables.sql', ZipArchive::EM_AES_256, 'synthetic');
        $zip->close();
        $this->expectExceptionMessage('encryption');
        Package::read($file, null);
    }

    public function testPermissionsRespectLiteralUnderscoresAndDatabaseBoundaries(): void
    {
        $grants = ["GRANT ALL PRIVILEGES ON `rrze\\_cli\\_test\\_%`.* TO 'fixture'@'localhost'"];
        self::assertSame([], Privileges::missing($grants, 'rrze_cli_test_example'));
        self::assertSame(Privileges::REQUIRED, Privileges::missing($grants, 'rrzeXcliXtestXexample'));
        self::assertSame(Privileges::REQUIRED, Privileges::missing($grants, 'production'));
        self::assertSame([], Privileges::missing(['GRANT ALL PRIVILEGES ON *.* TO x'], 'example'));
        self::assertSame(Privileges::REQUIRED, Privileges::missing($grants, 'rrze_cli_test_example', true));
        self::assertSame([], Privileges::missing(['GRANT ALL PRIVILEGES ON `example`.* TO x'], 'example', true));
        self::assertSame(['CREATE', 'DROP', 'ALTER', 'INDEX', 'LOCK TABLES'], Privileges::missing(['GRANT SELECT, INSERT, UPDATE, DELETE ON `example`.* TO x'], 'example'));
        self::assertSame(Privileges::REQUIRED, Privileges::missing(['GRANT SELECT ON `example`.`posts` TO x'], 'example'));
        self::assertSame(Privileges::REQUIRED, Privileges::missing(['GRANT ALL PRIVILEGES ON *.* TO x', 'REVOKE INSERT ON `example`.* FROM x'], 'example'));
    }

    public function testSqlMappingPreservesSqlExamplesInsideContent(): void
    {
        $sql = <<<'SQL'
-- CREATE TABLE `foreign_comment` (id int);
CREATE TABLE `src_posts` (`id` int, `content` longtext);
INSERT INTO `src_posts` VALUES (1,'CREATE TABLE `foreign_content` (id int); INSERT INTO `src_posts` VALUES (2);'),(2,'escaped \' quote');
/*!40000 ALTER TABLE `src_posts` DISABLE KEYS */;
SQL;
        $mapped = \RRZE\CLI\Migration\Sql::map($sql, ['src_posts' => 'dst_3_posts']);
        self::assertSame(['src_posts'], \RRZE\CLI\Migration\Sql::tables($sql));
        self::assertStringContainsString("'CREATE TABLE `foreign_content` (id int); INSERT INTO `src_posts` VALUES (2);'", $mapped);
        self::assertStringContainsString('INSERT INTO `dst_3_posts` VALUES', $mapped);
        self::assertStringContainsString('ALTER TABLE `dst_3_posts` DISABLE KEYS', $mapped);
        $this->expectExceptionMessage('outside the source');
        \RRZE\CLI\Migration\Sql::map($sql . PHP_EOL . 'INSERT INTO `foreign` VALUES (1);', ['src_posts' => 'dst_3_posts']);
    }

    public function testUserCountLimitStopsOversizedCsv(): void
    {
        $file = $this->workspace . '/users.csv';
        file_put_contents($file, "ID,user_login,user_email,role\n1,first,first@company.example,editor\n2,second,second@company.example,author\n");
        $this->expectExceptionMessage('user count limit');
        \RRZE\CLI\Migration\Users::read($file, 1);
    }

    public function testMemoryBudgetIsCheckedWithoutLargeAllocations(): void
    {
        $before = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '128M');
            $this->expectExceptionMessage('memory budget');
            Files::memory(PHP_INT_MAX);
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    public function testStorageCheckDoesNotCreateDestination(): void
    {
        $path = $this->workspace . '/not-created/subdirectory';
        $space = Files::capacity($path, 1);
        self::assertGreaterThan(0, $space['available_bytes']);
        self::assertDirectoryDoesNotExist($path);
        $this->expectExceptionMessage('disk space');
        Files::capacity($path, PHP_INT_MAX);
    }
}
