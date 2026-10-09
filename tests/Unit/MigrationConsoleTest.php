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

    public function testCopiedMultilineCommandPreservesArgumentsInTheShell(): void
    {
        $arguments = ["/path/Grüße with 'quotes' and \"double quotes\"/file", '/path/$(false); `false` *', str_repeat('long-path-', 30)];
        $output = fopen('php://memory', 'w+');
        try {
            $command = Console::shell([PHP_BINARY, '-r', 'echo json_encode(array_slice($argv, 1));', '--', ...$arguments]);
            (new Console($output, true))->command('1. Preview', 'Copy the command below.', $command);
            rewind($output);
            $text = stream_get_contents($output);
            $copied = substr($text, strpos($text, escapeshellarg(PHP_BINARY)));
            // Execute exactly the printed command: no frame characters, ANSI or
            // inserted newlines inside quoted paths may change the arguments.
            $process = proc_open(['/bin/sh', '-c', $copied], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $result = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame($arguments, json_decode($result, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            fclose($output);
        }
    }

    public function testCommandArgumentsWithControlCharactersAreRejectedInsteadOfChanged(): void
    {
        $this->expectExceptionMessage('control characters');
        Console::shell(['rsync', "/path/with\nnewline"]);
    }
}
