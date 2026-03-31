<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class ClearCommand implements SlashCommand
{
    public function name(): string
    {
        return '/clear';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Clear the terminal screen';
    }

    public function immediate(): bool
    {
        return false;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        echo "\033[2J\033[H";

        return SlashCommandResult::continue();
    }
}
