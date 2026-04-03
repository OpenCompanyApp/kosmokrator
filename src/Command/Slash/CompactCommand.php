<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Forces an immediate context compaction to reduce conversation history size.
 */
class CompactCommand implements SlashCommand
{
    public function name(): string
    {
        return '/compact';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Force context compaction';
    }

    /** @return bool Whether this command requires an agent turn after execution */
    public function immediate(): bool
    {
        return false;
    }

    /**
     * @param  string               $args  Unused command arguments
     * @param  SlashCommandContext  $ctx   Current session context with agent loop access
     * @return SlashCommandResult   Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->agentLoop->performCompaction();

        return SlashCommandResult::continue();
    }
}
