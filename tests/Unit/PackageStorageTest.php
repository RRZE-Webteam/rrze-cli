<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, PackageStorage, Run};

final class PackageStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Files::workspace());
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testExportPlanCreatesNothingAndRepeatedNamesHaveSeparatePrivateDirectories(): void
    {
        $first = PackageStorage::exportPath($this->root, 'My site', 5);
        $second = PackageStorage::exportPath($this->root, 'My site', 5);
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('~/export-\d{8}-\d{6}-site-5-[a-f0-9]{16}/My site\.zip$~D', $first);
        self::assertSame([], glob($this->root . '/*'));
        PackageStorage::reserveExport($first);
        file_put_contents($first, 'original export');
        PackageStorage::reserveExport($second);
        self::assertSame('original export', file_get_contents($first));
        self::assertSame(0700, fileperms(dirname($first)) & 0777);
        self::assertSame(0600, fileperms($first) & 0777);
        $this->expectExceptionMessage('Existing exports are never replaced');
        PackageStorage::reserveExport($first);
    }

    #[DataProvider('unsafeNames')]
    public function testExportCannotChooseAPathOutsideItsPrivateDirectory(string $name): void
    {
        $this->expectExceptionMessage('filename without a directory');
        PackageStorage::exportPath($this->root, $name, 5);
    }

    public static function unsafeNames(): array
    {
        return array_map(static fn ($name) => [$name], ['', '../public.zip', '/tmp/public.zip', 'folder/public.zip', 'folder\\public.zip', '.hidden', "a\n.zip", 'https://example.test/file.zip']);
    }

    public function testRelativeInputUsesPrivateRootAndPreservesOriginal(): void
    {
        mkdir($this->root . '/incoming', 0700);
        $file = $this->root . '/incoming/site.zip';
        file_put_contents($file, 'private input');
        self::assertSame($file, PackageStorage::input('incoming/site.zip', ['run-dir' => $this->root]));
        self::assertSame($file, PackageStorage::input($file, []));
        self::assertSame('private input', file_get_contents($file));
    }

    public function testInputCannotUseTheWordPressWebRoot(): void
    {
        $this->expectExceptionMessage('inside WordPress or wp-content');
        PackageStorage::input(ABSPATH . 'composer.json', []);
    }

    public function testSymlinkedParentCannotDisguiseAWebRootInput(): void
    {
        symlink(ABSPATH, $this->root . '/public');
        $this->expectExceptionMessage('inside WordPress or wp-content');
        PackageStorage::input('public/composer.json', ['run-dir' => $this->root]);
    }

    public function testRelativeInputCannotEscapeViaASymlinkedDirectory(): void
    {
        mkdir($this->root . '/storage', 0700);
        file_put_contents($this->root . '/outside.zip', 'private input');
        symlink($this->root, $this->root . '/storage/link');
        $this->expectExceptionMessage('escapes the private migration directory');
        PackageStorage::input('link/outside.zip', ['run-dir' => $this->root . '/storage']);
    }

    public function testPlanningAMissingRootDoesNotCreateItButStatusStillRejectsIt(): void
    {
        $path = $this->root . '/new-storage';
        self::assertSame($path, PackageStorage::root(['run-dir' => $path]));
        self::assertDirectoryDoesNotExist($path);
        $this->expectExceptionMessage('private permissions');
        Run::root($path, [ABSPATH], false);
    }

    public function testStorageInsideTheWebRootIsRejectedEvenBeforeCreation(): void
    {
        $this->expectExceptionMessage('outside the web roots');
        PackageStorage::root(['run-dir' => ABSPATH . 'forbidden-storage']);
    }

    public function testInputCannotUseTraversalOrImplicitWebRootFallback(): void
    {
        try {
            PackageStorage::input('composer.json', ['run-dir' => $this->root]);
            self::fail('A relative input must not fall back to the WordPress root.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('readable local ZIP', $error->getMessage());
        }
        $this->expectExceptionMessage('without dot segments');
        PackageStorage::input('../outside.zip', ['run-dir' => $this->root]);
    }
}
