<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Terminates the agent session and exits the application.
 */
class QuitCommand implements SlashCommand
{
    public function name(): string
    {
        return '/quit';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return ['/exit', '/q'];
    }

    public function description(): string
    {
        return 'Exit the agent';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::quit();
    }
}
