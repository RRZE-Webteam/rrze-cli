<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Tests\Process;

require_once dirname(__DIR__) . '/Support/Process.php';

final class ProcessTest extends TestCase
{
    public function testArgumentsArePassedLiterallyWithoutShellExpansion(): void
    {
        $argument = 'path with spaces; $(do-not-execute) "quoted"';
        $result = Process::run([PHP_BINARY, '-r', 'echo $argv[1];', $argument], __DIR__);
        self::assertSame(0, $result['code']);
        self::assertSame($argument, $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testNonzeroExitAndStderrArePreserved(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'fwrite(STDERR, "failed"); exit(17);'], __DIR__);
        self::assertSame(17, $result['code']);
        self::assertSame('failed', $result['stderr']);
    }

    public function testTimeoutStopsTheSubprocess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');
        Process::run([PHP_BINARY, '-r', 'sleep(10);'], __DIR__, null, 1);
    }
}
