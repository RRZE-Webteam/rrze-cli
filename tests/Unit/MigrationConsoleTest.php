<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Console;

final class MigrationConsoleTest extends TestCase
{
    public function testStepsOnlyReportSuccessAfterTheActionReturns(): void
    {
        $output = fopen('php://memory', 'w+');
        try {
            $console = new Console($output, true);
            self::assertSame(42, $console->step('Successful step', static fn () => 42));
            try {
                $console->step('Failed step', static fn () => throw new RuntimeException('original failure'));
                self::fail('The original failure must propagate.');
            } catch (RuntimeException $failure) {
                self::assertSame('original failure', $failure->getMessage());
            }
            rewind($output);
            $text = preg_replace('/\x1b\[[0-9;]*m/', '', stream_get_contents($output));
            self::assertStringContainsString('✓ Successful step', $text);
            self::assertStringContainsString('✕ Failed step', $text);
            self::assertStringNotContainsString('✓ Failed step', $text);
        } finally {
            fclose($output);
        }
    }

    public function testUntrustedMessagesCannotClearTheTerminal(): void
    {
        $output = fopen('php://memory', 'w+');
        try {
            (new Console($output, true))->status('!', "untrusted\033[2J\rspoof");
            rewind($output);
            $text = stream_get_contents($output);
            self::assertStringNotContainsString("\033[2J", $text);
            self::assertStringNotContainsString("\r", $text);
            self::assertStringContainsString('spoof', $text);
        } finally {
            fclose($output);
        }
    }
}
