<?php

namespace RRZE\CLI\Migration;

use RuntimeException;
use Laravel\Prompts\{Prompt, TextPrompt, SelectPrompt, ConfirmPrompt};

/** Rich or line-oriented prompts; explicit approval with terminal state restored on exit. */
final class Terminal
{
    public function __construct(private $input, private $output, public readonly bool $rich = false)
    {
        if ($this->rich) {
            if (!$this->interactive() || $input !== STDIN) {
                throw new RuntimeException('Rich prompts require the interactive process terminal.');
            }
            PromptEnvironment::configure($output);
        }
    }

    public function interactive(): bool
    {
        return stream_isatty($this->input) && stream_isatty($this->output);
    }

    public static function safe(string $value): string
    {
        // Render control characters visibly, including terminal escapes from package metadata.
        $value = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true);
        return preg_replace_callback('/[\p{C}]/u', static fn ($match) => json_encode($match[0]), $value);
    }

    public function line(string $message): void
    {
        fwrite($this->output, self::safe($message) . PHP_EOL);
    }

    public function ask(string $question, string $default = '', ?\Closure $validate = null): string
    {
        if ($this->rich) {
            $prompt = new TextPrompt(
                label: self::safe($question . ($default === '' ? '' : ' [' . $default . ']')),
                placeholder: self::safe($default),
                hint: 'Enter to submit · !quit or Ctrl+C to cancel',
                transform: static function (string $value) use ($default): string {
                    $value = trim($value, " \r\n");
                    if ($value === '!quit') {
                        throw new RuntimeException('Wizard cancelled by the operator.');
                    }
                    return $value === '' ? $default : $value;
                },
                validate: static function (string $value) use ($validate): ?string {
                    if (strlen($value) > 8192 || !preg_match('//u', $value) || preg_match('/\p{C}/u', $value)) {
                        return 'Enter plain text without control characters (maximum 8192 bytes).';
                    }
                    try {
                        $validate?->__invoke($value);
                        return null;
                    } catch (RuntimeException $failure) {
                        return self::safe($failure->getMessage());
                    }
                }
            );
            return $this->runPrompt($prompt);
        }
        while (true) {
            fwrite($this->output, self::safe($question . ($default === '' ? '' : ' [' . $default . ']')) . ': ');
            $answer = $this->readLine();
            if ($answer === false) {
                throw new RuntimeException('Wizard cancelled: input ended before confirmation.');
            }
            if (!str_ends_with($answer, "\n") || strlen($answer) > 8192) {
                throw new RuntimeException('Wizard input is incomplete or too long.');
            }
            $answer = trim($answer, " \r\n");
            if ($answer === '!quit') {
                throw new RuntimeException('Wizard cancelled by the operator.');
            }
            if (!preg_match('//u', $answer) || preg_match('/\p{C}/u', $answer)) {
                $this->line('Enter plain text without control characters.');
                continue;
            }
            $answer = $answer === '' ? $default : $answer;
            try {
                if ($validate !== null) {
                    $validate($answer);
                }
                return $answer;
            } catch (RuntimeException $error) {
                $this->line($error->getMessage());
            }
        }
    }

    public function choice(string $question, array $choices, string $default): string
    {
        if ($this->rich) {
            return $this->select($question, array_combine($choices, $choices), $default);
        }
        return $this->ask($question . ' (' . implode('/', $choices) . ')', $default, static function ($value) use ($choices): void {
            if (!in_array($value, $choices, true)) {
                throw new RuntimeException('Choose one of: ' . implode(', ', $choices));
            }
        });
    }

    public function confirm(string $question): bool
    {
        if ($this->rich) {
            return $this->runPrompt(new ConfirmPrompt(label: self::safe($question), default: false,
                hint: 'Default: No · arrows or y/n, then Enter · Ctrl+C to cancel'));
        }
        return $this->choice($question, ['yes', 'no'], 'no') === 'yes';
    }

    public function select(string $question, array $choices, string $default): string
    {
        return $this->runPrompt(new SelectPrompt(label: self::safe($question),
            options: array_map(self::safe(...), $choices), default: $default, scroll: 8,
            hint: '↑/↓ to select · Enter to confirm · Ctrl+C to cancel'));
    }

    private function runPrompt(Prompt $prompt): mixed
    {
        $quit = '';
        $prompt->on('key', static function (string $key) use ($prompt, &$quit): void {
            $quit = substr($quit . $key, -5);
            if ($quit === '!quit') {
                throw new RuntimeException('Wizard cancelled by the operator.');
            }
            if (is_string($prompt->value()) && strlen($prompt->value()) > 8192) {
                throw new RuntimeException('Wizard input is incomplete or too long.');
            }
        });
        try {
            $answer = $prompt->prompt();
            if ($answer === null) {
                throw new RuntimeException('Wizard cancelled: input ended before confirmation.');
            }
            return $answer;
        } finally {
            $prompt->clearListeners();
            Prompt::terminal()->restoreTty();
            fwrite($this->output, "\033[?25h");
        }
    }

    public function identity(string $label, string $expected): bool
    {
        $this->line($label . ': ' . $expected);
        return hash_equals($expected, $this->ask('Type that complete URL to confirm, or leave empty to cancel'));
    }

    private function readLine(): string|false
    {
        if (!stream_isatty($this->input)) {
            return fgets($this->input, 8194);
        }
        // A blocking fgets can defer PHP signal handlers indefinitely on a terminal.
        $blocking = stream_get_meta_data($this->input)['blocked'];
        if (!stream_set_blocking($this->input, false)) {
            throw new RuntimeException('Cannot read the interactive terminal safely.');
        }
        try {
            $line = '';
            while (strlen($line) < 8193 && !str_ends_with($line, "\n")) {
                $chunk = fgets($this->input, 8194 - strlen($line));
                if ($chunk !== false) {
                    $line .= $chunk;
                } elseif (feof($this->input)) {
                    return $line === '' ? false : $line;
                } else {
                    usleep(20000);
                }
            }
            return $line;
        } finally {
            stream_set_blocking($this->input, $blocking);
        }
    }
}
