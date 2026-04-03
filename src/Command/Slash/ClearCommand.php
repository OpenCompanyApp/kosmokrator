<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Clears the terminal screen using ANSI escape sequences.
 */
class ClearCommand implements SlashCommand
{
    public function name(): string
    {
        return '/clear';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Clear the terminal screen';
    }

    /** @return bool Whether this command requires an agent turn after execution */
    public function immediate(): bool
    {
        return false;
    }

    /**
     * @param  string               $args  Unused command arguments
     * @param  SlashCommandContext  $ctx   Current session context
     * @return SlashCommandResult   Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        // ANSI escape: clear screen and move cursor to home position
        echo "\033[2J\033[H";

        return SlashCommandResult::continue();
    }
}
