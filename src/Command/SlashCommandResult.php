<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Value object returned by SlashCommand::execute() indicating what the REPL should do next.
 */
readonly class SlashCommandResult
{
    private function __construct(
        public SlashCommandAction $action,
        public ?string $input = null,
    ) {}

    /**
     * Signals the REPL to continue normally.
     */
    public static function continue(): self
    {
        return new self(SlashCommandAction::Continue);
    }

    /**
     * Signals the REPL to exit the agent loop.
     */
    public static function quit(): self
    {
        return new self(SlashCommandAction::Quit);
    }

    /**
     * Signals the REPL to feed the given input string into the next iteration as if the user typed it.
     *
     * @param  string  $input  Text to inject as the next user prompt
     */
    public static function inject(string $input): self
    {
        return new self(SlashCommandAction::Inject, $input);
    }
}
