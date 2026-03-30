<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

readonly class SlashCommandResult
{
    private function __construct(
        public SlashCommandAction $action,
        public ?string $input = null,
    ) {}

    public static function continue(): self
    {
        return new self(SlashCommandAction::Continue);
    }

    public static function quit(): self
    {
        return new self(SlashCommandAction::Quit);
    }

    public static function inject(string $input): self
    {
        return new self(SlashCommandAction::Inject, $input);
    }
}
