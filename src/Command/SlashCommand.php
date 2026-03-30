<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

interface SlashCommand
{
    public function name(): string;

    /** @return string[] */
    public function aliases(): array;

    public function description(): string;

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult;
}
