<?php

namespace RRZE\CLI\Migration;

use Laravel\Prompts\{Prompt, TextPrompt, SelectPrompt, ConfirmPrompt};
use Symfony\Component\Console\Output\{OutputInterface, StreamOutput};

/** Adapt Prompts' shared terminal without changing the library or evaluating user markup. */
abstract class PromptEnvironment extends Prompt
{
    public static function configure($output): void
    {
        static::flushState();
        static::$terminal = new PromptInput();
        static::interactive(true);
        static::cancelUsing(static fn () => throw new \RuntimeException('Wizard cancelled by the operator.'));
        foreach ([TextPrompt::class, SelectPrompt::class, ConfirmPrompt::class] as $prompt) {
            $prompt::fallbackUsing(static fn () => throw new \RuntimeException('Cannot control this terminal. Restart the wizard with --plain.'));
        }
        static::setOutput(new class($output, OutputInterface::VERBOSITY_NORMAL, true) extends StreamOutput {
            // WP-CLI may already have loaded an older Symfony with untyped parameters.
            public function write($messages, $newline = false, $options = 0): void
            {
                parent::write($messages, $newline, OutputInterface::OUTPUT_RAW);
            }
        });
    }
}
