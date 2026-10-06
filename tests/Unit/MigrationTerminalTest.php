<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Terminal;

final class MigrationTerminalTest extends TestCase
{
    private $input;
    private $output;

    protected function tearDown(): void
    {
        fclose($this->input);
        fclose($this->output);
    }

    private function terminal(string $answers): Terminal
    {
        $this->input = fopen('php://memory', 'w+');
        $this->output = fopen('php://memory', 'w+');
        fwrite($this->input, $answers);
        rewind($this->input);
        return new Terminal($this->input, $this->output);
    }

    public function testEmptyConfirmationNeverApprovesAndPipesAreNotInteractive(): void
    {
        $terminal = $this->terminal("\n");
        self::assertFalse($terminal->interactive());
        self::assertFalse($terminal->confirm('Import'));
    }

    public function testConfirmationRequiresExplicitYesAfterInvalidAnswer(): void
    {
        self::assertTrue($this->terminal("y\nyes\n")->confirm('Import'));
    }

    public function testDestinationIdentityRequiresExactUrl(): void
    {
        $terminal = $this->terminal("https://wrong.test/\nhttps://target.test/site/\n");
        self::assertFalse($terminal->identity('Destination', 'https://target.test/site/'));
        self::assertTrue($terminal->identity('Destination', 'https://target.test/site/'));
    }

    public function testEndOfInputCannotConfirm(): void
    {
        $this->expectExceptionMessage('input ended');
        $this->terminal('')->confirm('Import');
    }

    public function testQuitDoesNotUseTheDefault(): void
    {
        $this->expectExceptionMessage('cancelled by the operator');
        $this->terminal("!quit\n")->ask('File', 'website.zip');
    }

    public function testOverlongInputIsNotReusedAsAnAnswerToAnotherPrompt(): void
    {
        $this->expectExceptionMessage('too long');
        $this->terminal(str_repeat('x', 9000) . "\nyes\n")->ask('Filename');
    }

    public function testControlCharactersAreRejectedAndUntrustedOutputIsEscaped(): void
    {
        $terminal = $this->terminal("site\033[2J\nyes\0\nvalid.zip\n");
        self::assertSame('valid.zip', $terminal->ask('Filename'));
        $terminal->line("Source: test\033[2J\rspoof\u{202E}end");
        rewind($this->output);
        $output = stream_get_contents($this->output);
        self::assertStringNotContainsString("\033", $output);
        self::assertStringNotContainsString("\r", $output);
        self::assertStringNotContainsString("\u{202E}", $output);
        self::assertStringContainsString('plain text', $output);
    }

    public function testValidationRetriesAndDefaultsRemainVisible(): void
    {
        $terminal = $this->terminal("invalid\n\n");
        $result = $terminal->ask('Mode', 'preview', static function ($value): void {
            if ($value !== 'preview') {
                throw new RuntimeException('Invalid mode');
            }
        });
        self::assertSame('preview', $result);
        rewind($this->output);
        self::assertStringContainsString('Mode [preview]', stream_get_contents($this->output));
    }
}
