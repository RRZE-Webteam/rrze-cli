<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Tests\Process;
use RRZE\CLI\Tests\WpCli;

require_once dirname(__DIR__) . '/Support/Process.php';
require_once dirname(__DIR__) . '/Support/WpCli.php';

final class WpCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rrze-wp-cli-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->directory) as $entry) {
            unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testComposerShellProxyIsSkippedBeforeReadingDatabaseHost(): void
    {
        $phpEntry = $this->createExecutable('wp', "#!/usr/bin/env php\n<?php echo \"127.0.0.1:3306\\n\";\n");
        $composerBin = dirname(__DIR__, 2) . '/vendor/bin';
        $executable = WpCli::findExecutable('wp', $composerBin . PATH_SEPARATOR . $this->directory);

        self::assertSame(realpath($phpEntry), $executable);
        self::assertSame("127.0.0.1:3306\n", Process::checked([
            PHP_BINARY, '-d', 'display_errors=stderr', $executable, 'config', 'get', 'DB_HOST', '--type=constant',
        ], $this->directory));
    }

    public function testExplicitPhpEntryPointTakesPrecedenceOverPath(): void
    {
        $phpEntry = $this->createExecutable('custom.php', "<?php echo 'explicit';\n");
        $this->createExecutable('wp', "#!/usr/bin/env php\n<?php echo 'path';\n");

        self::assertSame(realpath($phpEntry), WpCli::findExecutable($phpEntry, $this->directory));
    }

    public function testExplicitShellWrapperIsRejectedEvenWithPhpAvailableOnPath(): void
    {
        $wrapper = $this->createExecutable('wrapper', "#!/bin/sh\nexec php wp-cli.phar \"\$@\"\n");
        $this->createExecutable('wp', "#!/usr/bin/env php\n<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RRZE_TEST_WP_CLI');
        WpCli::findExecutable($wrapper, $this->directory);
    }

    public function testOnlyShellWrappersOnPathProducesAnActionableError(): void
    {
        $this->createExecutable('wp', "#!/bin/sh\nexec php wp-cli.phar \"\$@\"\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a shell wrapper');
        WpCli::findExecutable('wp', $this->directory);
    }

    public function testMissingExecutableProducesAnActionableError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WP-CLI PHP entry point found');
        WpCli::findExecutable('wp', $this->directory);
    }

    private function createExecutable(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0700);
        return $path;
    }
}
