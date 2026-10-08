<?php

namespace RRZE\CLI\Migration;

/** Nonblocking input keeps signals and EOF effective while a Laravel prompt is open. */
final class PromptInput extends \Laravel\Prompts\Terminal
{
    private string $buffer = '';

    public function read(): string
    {
        $blocking = stream_get_meta_data(STDIN)['blocked'];
        if (!stream_set_blocking(STDIN, false)) {
            throw new \RuntimeException('Cannot read the interactive terminal safely.');
        }
        $partialSince = null;
        try {
            while (true) {
                $chunk = fread(STDIN, 1024);
                if ($chunk !== false) {
                    $this->buffer .= $chunk;
                }
                if (str_contains($this->buffer, "\x03")) {
                    throw new \RuntimeException('Wizard cancelled by the operator.');
                }
                if (str_contains($this->buffer, "\x04")) {
                    throw new \RuntimeException('Wizard cancelled: input ended before confirmation.');
                }
                if ($this->buffer !== '') {
                    $key = $this->nextKey();
                    if ($key !== null) {
                        $this->buffer = substr($this->buffer, strlen($key));
                        return $key === "\r" ? "\n" : $key;
                    }
                    // Allow split UTF-8 and arrow sequences, but never wait indefinitely on malformed input.
                    $partialSince ??= microtime(true);
                    if (microtime(true) - $partialSince > 0.25 || strlen($this->buffer) > 8192) {
                        throw new \RuntimeException('Wizard cancelled: input contains unsupported control characters.');
                    }
                }
                if (feof(STDIN)) {
                    throw new \RuntimeException('Wizard cancelled: input ended before confirmation.');
                }
                usleep(20000);
            }
        } finally {
            stream_set_blocking(STDIN, $blocking);
        }
    }

    private function nextKey(): ?string
    {
        static $known = null;
        if ($known === null) {
            $values = array_values((new \ReflectionClass(\Laravel\Prompts\Key::class))->getConstants());
            $known = array_merge(...array_map(static fn ($value) => (array) $value, $values));
            // Escape on its own is not a navigation key; unsupported escape sequences must not reach the renderer.
            $known = array_values(array_diff($known, ["\e"]));
            usort($known, static fn ($a, $b) => strlen($b) <=> strlen($a));
        }
        if (ord($this->buffer[0]) < 32 || $this->buffer[0] === "\x7f") {
            foreach ($known as $key) {
                if (str_starts_with($this->buffer, $key)) {
                    return $key;
                }
            }
            if ($this->buffer[0] === "\r") {
                return "\r";
            }
            foreach ($known as $key) {
                if (str_starts_with($key, $this->buffer)) {
                    return null;
                }
            }
            throw new \RuntimeException('Wizard cancelled: input contains unsupported control characters.');
        }
        preg_match('/^[^\x00-\x1f\x7f]+/', $this->buffer, $match);
        $text = $match[0];
        if (!preg_match('//u', $text)) {
            return null;
        }
        if (preg_match('/\p{C}/u', $text)) {
            throw new \RuntimeException('Wizard cancelled: input contains unsupported control characters.');
        }
        return $text;
    }

    public function restoreTty(): void
    {
        $this->buffer = '';
        parent::restoreTty();
    }
}
