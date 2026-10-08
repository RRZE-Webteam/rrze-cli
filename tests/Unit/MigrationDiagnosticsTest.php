<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Diagnostics, Files};

final class MigrationDiagnosticsTest extends TestCase
{
    public function testRepeatedOriginsAreAggregatedAndMemoryIsBounded(): void
    {
        $diagnostics = new Diagnostics();
        for ($i = 0; $i < 1000; $i++) {
            $diagnostics->record('php_deprecation', '/plugin/file.php', 5);
        }
        for ($i = 0; $i < 120; $i++) {
            $diagnostics->record('php_deprecation', '/plugin/' . $i . '.php', 1);
        }
        $report = $diagnostics->report();
        self::assertSame(1120, $report['events']);
        self::assertCount(100, $report['entries']);
        self::assertSame(1000, $report['entries'][0]['count']);
        self::assertSame(21, $report['omitted_events']);
        self::assertFalse($report['message_bodies_recorded']);
    }

    public function testReportsArePrivateAndCannotOverwriteEachOther(): void
    {
        $directory = Files::workspace();
        try {
            $diagnostics = new Diagnostics();
            $diagnostics->record('php_deprecation', "/plugin/\033[2Jfile.php", 1);
            $first = $diagnostics->save($directory);
            $second = $diagnostics->save($directory);
            self::assertNotSame($first, $second);
            self::assertSame(0600, fileperms($first) & 0777);
            self::assertSame($diagnostics->report(), json_decode(file_get_contents($first), true, 512, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString("\033", $diagnostics->report()['entries'][0]['origin']);
            chmod($directory, 0755);
            $this->expectExceptionMessage('private, owned directory');
            $diagnostics->save($directory);
        } finally {
            Files::remove($directory);
        }
    }
}
