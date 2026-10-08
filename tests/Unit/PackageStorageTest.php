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

    public function testDiscoveryListsDistinctRelativePathsNewestFirstWithoutOpeningArchives(): void
    {
        $paths = ['export-one/site.zip', 'incoming/site.zip', 'run-id/package.ZIP', 'older.zip'];
        foreach ($paths as $index => $path) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0700);
            }
            file_put_contents($this->root . '/' . $path, 'not yet a validated package');
            touch($this->root . '/' . $path, 1700000000 - ($index === 3 ? 100 : 0));
        }
        file_put_contents($this->root . '/run-id/run.json', '{}');
        $listing = PackageStorage::packages(['run-dir' => $this->root]);
        self::assertSame($paths, array_column($listing['files'], 'path'));
        self::assertFalse($listing['incomplete']);
        self::assertSame(1700000000, $listing['files'][0]['modified']);
        self::assertSame(27, $listing['files'][0]['bytes']);
        self::assertSame('not yet a validated package', file_get_contents($this->root . '/older.zip'));
    }

    public function testDiscoveryOmitsSymlinksAndUnsafeNames(): void
    {
        mkdir($this->root . '/incoming', 0700);
        file_put_contents($this->root . '/incoming/site.zip', 'ZIP');
        symlink($this->root . '/incoming/site.zip', $this->root . '/link.zip');
        symlink($this->root, $this->root . '/incoming/loop');
        symlink(ABSPATH, $this->root . '/webroot');
        file_put_contents($this->root . "/spoof\033[2J.zip", 'ZIP');
        file_put_contents($this->root . "/spoof\u{202E}.zip", 'ZIP');
        file_put_contents($this->root . '/back\\slash.zip', 'ZIP');
        $listing = PackageStorage::packages(['run-dir' => $this->root]);
        self::assertSame(['incoming/site.zip'], array_column($listing['files'], 'path'));
        self::assertFalse($listing['incomplete']);
    }

    public function testEmptyAndMissingDirectoriesNeedNoFilesystemChanges(): void
    {
        foreach ([$this->root, $this->root . '/missing'] as $root) {
            self::assertSame(['files' => [], 'incomplete' => false], PackageStorage::packages(['run-dir' => $root]));
        }
        self::assertSame([], glob($this->root . '/*'));
    }

    public function testDiscoveryLimitsDisplayAndDepthButUnlistedInputsRemainUsable(): void
    {
        for ($index = 0; $index < 51; $index++) {
            file_put_contents($this->root . '/file-' . $index . '.zip', 'ZIP');
            touch($this->root . '/file-' . $index . '.zip', 1700000000 + $index);
        }
        mkdir($this->root . '/one/two/three/four', 0700, true);
        file_put_contents($this->root . '/one/two/three/four/deep.zip', 'ZIP');
        file_put_contents($this->root . '/one/two/three/visible.zip', 'ZIP');
        $listing = PackageStorage::packages(['run-dir' => $this->root]);
        $paths = array_column($listing['files'], 'path');
        self::assertCount(50, $paths);
        self::assertTrue($listing['incomplete']);
        self::assertContains('one/two/three/visible.zip', $paths);
        self::assertNotContains('one/two/three/four/deep.zip', $paths);
        self::assertNotContains('file-0.zip', $paths);
        self::assertSame($this->root . '/file-0.zip', PackageStorage::input('file-0.zip', ['run-dir' => $this->root]));
        self::assertSame($this->root . '/one/two/three/four/deep.zip', PackageStorage::input('one/two/three/four/deep.zip', ['run-dir' => $this->root]));
    }

    public function testDiscoveryStopsScanningLargeUnrelatedDirectoryTrees(): void
    {
        for ($index = 0; $index < 5001; $index++) {
            file_put_contents($this->root . '/entry-' . $index . '.txt', '');
        }
        self::assertSame(['files' => [], 'incomplete' => true], PackageStorage::packages(['run-dir' => $this->root]));
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

    public function testSelectionRejectsADirectoryReplacedByAnExternalProcessAfterDiscovery(): void
    {
        mkdir($this->root . '/storage/incoming', 0700, true);
        mkdir($this->root . '/outside', 0700);
        file_put_contents($this->root . '/storage/incoming/site.zip', 'original');
        file_put_contents($this->root . '/outside/site.zip', 'outside storage');
        self::assertCount(1, PackageStorage::packages(['run-dir' => $this->root . '/storage'])['files']);
        // Another process must perform the replacement so this process retains its realpath cache.
        $process = proc_open([PHP_BINARY, '-r',
            'rename($argv[1] . "/storage/incoming", $argv[1] . "/storage/original"); symlink($argv[1] . "/outside", $argv[1] . "/storage/incoming");',
            $this->root,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        self::assertSame(0, proc_close($process));
        $this->expectExceptionMessage('escapes the private migration directory');
        PackageStorage::input('incoming/site.zip', ['run-dir' => $this->root . '/storage']);
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
