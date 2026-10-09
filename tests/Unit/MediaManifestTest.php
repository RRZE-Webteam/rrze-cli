<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, MediaManifest, MediaTransfer};

final class MediaManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Files::workspace());
        mkdir($this->root . '/source/2026', 0700, true);
        file_put_contents($this->root . '/source/2026/Grüße.jpg', 'image bytes');
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    private function manifest(): array
    {
        return MediaManifest::capture(['basedir' => $this->root . '/source', 'baseurl' => 'https://source.test/wp-content/uploads/sites/5'], []);
    }

    public function testInventoryBindsRelativePathsAndBytesAndDetectsChangedTransfer(): void
    {
        $manifest = $this->manifest();
        self::assertSame(['2026/Grüße.jpg' => ['bytes' => 11, 'sha256' => hash('sha256', 'image bytes')]], $manifest['files']);
        self::assertSame("2026/Grüße.jpg\0", MediaManifest::fileList($manifest));
        mkdir($this->root . '/target/2026', 0700, true);
        copy($this->root . '/source/2026/Grüße.jpg', $this->root . '/target/2026/Grüße.jpg');
        self::assertSame(['files' => 1, 'bytes' => 11], MediaManifest::verify($manifest, $this->root . '/target'));
        file_put_contents($this->root . '/target/2026/Grüße.jpg', 'other bytes');
        $this->expectExceptionMessage('Media verification failed');
        MediaManifest::verify($manifest, $this->root . '/target');
    }

    public function testMissingFilesCannotPassVerification(): void
    {
        $this->expectExceptionMessage('missing, changed or unsafe');
        MediaManifest::verify($this->manifest(), $this->root . '/missing');
    }

    public function testAnUnexpectedFileCannotSilentlyExpandTheTransferScope(): void
    {
        $manifest = $this->manifest();
        file_put_contents($this->root . '/source/extra.txt', 'unexpected');
        $this->expectExceptionMessage('Unexpected or unsafe');
        MediaManifest::verify($manifest, $this->root . '/source');
    }

    public function testLinkedParentCannotPassEvenWithMatchingFileContents(): void
    {
        $manifest = $this->manifest();
        mkdir($this->root . '/target', 0700);
        symlink($this->root . '/source/2026', $this->root . '/target/2026');
        $this->expectExceptionMessage('unsafe');
        MediaManifest::verify($manifest, $this->root . '/target');
    }

    public function testSourceLinksAndExecutableFilesCannotEnterTheTransferList(): void
    {
        symlink($this->root . '/source/2026/Grüße.jpg', $this->root . '/source/link.jpg');
        try {
            $this->manifest();
            self::fail('Source links must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Links and special files', $error->getMessage());
        }
        unlink($this->root . '/source/link.jpg');
        file_put_contents($this->root . '/source/index.php', '<?php');
        $this->expectExceptionMessage('Executable files');
        $this->manifest();
    }

    public function testTamperedManifestsCannotEscapeOrCauseCaseCollisions(): void
    {
        $base = $this->manifest();
        foreach (['../outside.jpg', '/absolute.jpg', 'x\nfile.jpg', '2026/GRÜßE.jpg', 'run.php', '2026'] as $name) {
            $manifest = $base;
            $manifest['files'][$name] = reset($manifest['files']);
            try {
                MediaManifest::validate($manifest);
                self::fail('Accepted unsafe inventory entry ' . $name);
            } catch (RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testRsyncUsesAnExplicitNullDelimitedListAndNeverDeletesOrOverwrites(): void
    {
        $manifest = $this->manifest();
        $layout = ['directory' => "/target/with ' quote"];
        $commands = MediaTransfer::commands($manifest, $layout, '/private/media-files.txt', 'operator@source.example', "/source/with ' quote");
        self::assertStringContainsString("'--dry-run'", $commands['preview']);
        self::assertStringNotContainsString('--dry-run', $commands['transfer']);
        foreach ($commands as $command) {
            self::assertStringContainsString("'--ignore-existing'", $command);
            self::assertStringContainsString("'--from0'", $command);
            self::assertStringContainsString("'--protect-args'", $command);
            self::assertStringContainsString("'--files-from=/private/media-files.txt'", $command);
            self::assertStringContainsString(escapeshellarg("operator@source.example:/source/with ' quote/"), $command);
            self::assertStringContainsString(escapeshellarg("/target/with ' quote/"), $command);
            self::assertStringNotContainsString('--delete', $command);
            self::assertStringNotContainsString('--links', $command);
        }
        $local = MediaTransfer::commands($manifest, $layout, '/private/list', null, '/snapshot');
        self::assertStringContainsString("'/snapshot/'", $local['transfer']);
        self::assertStringNotContainsString('--protect-args', $local['transfer']);
        $multiline = MediaTransfer::commands($manifest, $layout, '/private/list', null, '/snapshot', true);
        self::assertSame($local, array_map(static fn ($command) => str_replace(" \\\n  ", ' ', $command), $multiline));
        $this->expectExceptionMessage('user@hostname');
        MediaTransfer::commands($manifest, $layout, '/private/list', '-e injected');
    }

    public function testMediaUrlRulesHandleSiteIdsRootRelativeAndJsonUrlsWithoutTouchingNeighbours(): void
    {
        $manifest = $this->manifest();
        $layout = ['baseurl' => 'https://target.test/new/wp-content/uploads/sites/12'];
        $source = 'https://source.test/wp-content/uploads/sites/5/a.jpg';
        $values = [$source, str_replace('/', '\\/', $source), '//source.test/wp-content/uploads/sites/5/a.jpg', '/wp-content/uploads/sites/5/a.jpg',
            'https://source.test/wp-content/uploads/sites/50/other.jpg', 'https://other.test/wp-content/uploads/sites/5/other.jpg'];
        foreach (MediaTransfer::urlRules($manifest, $layout) as [$pattern, $replacement]) {
            $values = array_map(static fn ($value) => preg_replace_callback('~' . $pattern . '~', static fn () => $replacement, $value), $values);
        }
        $expected = $layout['baseurl'] . '/a.jpg';
        self::assertSame([$expected, str_replace('/', '\\/', $expected), $expected, '/new/wp-content/uploads/sites/12/a.jpg',
            'https://source.test/wp-content/uploads/sites/50/other.jpg', 'https://other.test/wp-content/uploads/sites/5/other.jpg'], $values);
    }

    public function testMainSiteRulesExcludeOtherSitesAndExplicitlyExcludedDirectories(): void
    {
        $manifest = $this->manifest();
        $manifest['source_baseurl'] = 'https://source.test/wp-content/uploads';
        $manifest['excluded_directories'] = ['sites', 'backups'];
        $layout = ['baseurl' => 'https://target.test/wp-content/uploads/sites/12'];
        $value = 'https://source.test/wp-content/uploads/sites/5/a.jpg /wp-content/uploads/backups/a.jpg';
        foreach (MediaTransfer::urlRules($manifest, $layout) as [$pattern, $replacement]) {
            $value = preg_replace_callback('~' . $pattern . '~', static fn () => $replacement, $value);
        }
        self::assertSame('https://source.test/wp-content/uploads/sites/5/a.jpg /wp-content/uploads/backups/a.jpg', $value);
    }

    public function testProtectedFilesRequireAnAccessCheckButAnEmptyProtectedDirectoryDoesNot(): void
    {
        $manifest = $this->manifest();
        $manifest['protected_directories'] = ['_protected'];
        self::assertFalse(MediaTransfer::requiresAccessCheck($manifest));
        $manifest['files']['_protected/document.pdf'] = reset($manifest['files']);
        self::assertTrue(MediaTransfer::requiresAccessCheck($manifest));
    }
}
