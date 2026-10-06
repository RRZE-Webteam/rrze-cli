<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Files;
use RRZE\CLI\Utils;

final class MigrationFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Files::workspace();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            Files::remove($this->directory);
        }
    }

    public function testOutputCreationCannotOverwriteAnExistingFile(): void
    {
        $path = $this->directory . '/existing.zip';
        file_put_contents($path, 'protected original');
        try {
            Files::output($path);
            self::fail('Existing output must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame('protected original', file_get_contents($path));
        }
    }

    public function testWorkspaceAndNewOutputHavePrivatePermissions(): void
    {
        $file = $this->directory . '/private.csv';
        fclose(Files::output($file));
        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testCorruptArchiveProducesAnError(): void
    {
        $file = $this->directory . '/bad.zip';
        file_put_contents($file, 'PK this is not a valid archive');
        $this->expectException(RuntimeException::class);
        Utils::extract($file, $this->directory . '/extracted');
    }

    public function testArchiveCreationFailureIsNotReportedAsSuccess(): void
    {
        $this->expectException(RuntimeException::class);
        Utils::zip($this->directory . '/bad.zip', ['missing.csv' => $this->directory . '/missing.csv']);
    }

    public function testCleanupDoesNotFollowDirectorySymlinks(): void
    {
        mkdir($this->directory . '/outside');
        mkdir($this->directory . '/workspace');
        file_put_contents($this->directory . '/outside/keep.txt', 'protected');
        symlink($this->directory . '/outside', $this->directory . '/workspace/link');
        Files::remove($this->directory . '/workspace');
        self::assertSame('protected', file_get_contents($this->directory . '/outside/keep.txt'));
        self::assertDirectoryDoesNotExist($this->directory . '/workspace');
    }
}
