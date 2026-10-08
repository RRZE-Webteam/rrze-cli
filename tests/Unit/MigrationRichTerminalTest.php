<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RRZE\CLI\Tests\Process;

require_once dirname(__DIR__) . '/Support/Process.php';

final class MigrationRichTerminalTest extends TestCase
{
    private function runTerminal(string $mode, array $dialogue): array
    {
        $result = Process::terminal([PHP_BINARY, dirname(__DIR__) . '/fixtures/terminal.php', $mode], __DIR__,
            ['PATH' => getenv('PATH'), 'TERM' => 'xterm', 'COLUMNS' => '120', 'LINES' => '40'], $dialogue);
        self::assertSame(count($dialogue), $result['answers'], $result['stdout']);
        self::assertStringContainsString('TTY_RESTORED=yes', $result['stdout']);
        return $result;
    }

    public function testConfirmationDefaultsToNoAndRequiresExplicitSelection(): void
    {
        $no = $this->runTerminal('confirm', [['Execute migration', ["\n"]]]);
        self::assertSame(0, $no['code'], $no['stdout']);
        self::assertStringContainsString('ANSWER=false', $no['stdout']);
        $yes = $this->runTerminal('confirm', [['Execute migration', ['y', "\n"]]]);
        self::assertStringContainsString('ANSWER=true', $yes['stdout']);
    }

    public function testArrowSelectionAndUntrustedLabels(): void
    {
        $result = $this->runTerminal('select', [['Choose package', ["\033[B", "\n"]]]);
        self::assertSame(0, $result['code'], $result['stdout']);
        self::assertStringContainsString('ANSWER="a"', $result['stdout']);
        self::assertStringContainsString('<fg=red>', $result['stdout']);
        self::assertStringNotContainsString("file\033[2J", $result['stdout']);
    }

    public function testTypedTextReplacesDefaultAndValidationRetries(): void
    {
        $result = $this->runTerminal('text', [['Filename', ['Grüße.zip', "\n"]]]);
        self::assertStringContainsString('ANSWER="Grüße.zip"', $result['stdout']);
        $result = $this->runTerminal('validate', [['Mode', ['wrong', "\n"]], ['Invalid mode', ["\x7f", "\x7f", "\x7f", "\x7f", "\x7f", "\n"]]]);
        self::assertStringContainsString('ANSWER="preview"', $result['stdout']);
    }

    public function testIdentityStillRequiresTheCompleteExactUrl(): void
    {
        foreach (['https://wrong.test/' => 'false', 'https://target.test/site/' => 'true', '' => 'false'] as $url => $expected) {
            $result = $this->runTerminal('identity', [['Type that complete URL', [$url, "\n"]]]);
            self::assertStringContainsString('ANSWER=' . $expected, $result['stdout']);
        }
    }

    public function testBufferedKeysAndSplitUtf8AreHandledWithoutSwallowingEnter(): void
    {
        $selection = $this->runTerminal('select', [['Choose package', ["\e[B\e[B\n"]]]);
        self::assertStringContainsString('ANSWER="b"', $selection['stdout']);
        $text = $this->runTerminal('text', [['Filename', ["Gr\xc3", "\xbcße.zip\n"]]]);
        self::assertStringContainsString('ANSWER="Grüße.zip"', $text['stdout']);
    }

    #[DataProvider('cancellations')]
    public function testCancellationNeverConfirmsAndRestoresTheTerminal(array $keys, string $message): void
    {
        $result = $this->runTerminal('text', [['Filename', $keys]]);
        self::assertSame(1, $result['code'], $result['stdout']);
        self::assertStringContainsString($message, $result['stdout']);
        self::assertStringNotContainsString('ANSWER=', $result['stdout']);
    }

    public static function cancellations(): array
    {
        return [
            'interrupt' => [["\x03"], 'cancelled by the operator'],
            'EOF' => [["\x04"], 'input ended'],
            'quit' => [['!quit'], 'cancelled by the operator'],
            'escape injection' => [["\033[2J"], 'unsupported control characters'],
            'invalid UTF-8' => [["\xff"], 'unsupported control characters'],
        ];
    }

    public function testSignalAtPromptRestoresTheTerminal(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('PCNTL is unavailable.');
        }
        $result = $this->runTerminal('confirm', [['Execute migration', static function ($process) {
            proc_terminate($process, SIGTERM);
            return null;
        }]]);
        self::assertSame(1, $result['code'], $result['stdout']);
        self::assertStringContainsString('cancelled by a signal', $result['stdout']);
    }
}
