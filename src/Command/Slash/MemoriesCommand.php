<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Lists all stored memories for the current project with their IDs, types, and titles.
 */
class MemoriesCommand implements SlashCommand
{
    public function name(): string
    {
        return '/memories';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'List stored memories';
    }

    /** @return bool Whether this command executes immediately (no agent turn needed) */
    public function immediate(): bool
    {
        return true;
    }

    /**
     * @param  string               $args  Unused command arguments
     * @param  SlashCommandContext  $ctx   Current session context
     * @return SlashCommandResult   Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $memories = $ctx->sessionManager->getMemories();

        if ($memories === []) {
            $ctx->ui->showNotice('No memories stored yet.');
        } else {
            $lines = [];
            foreach ($memories as $m) {
                $lines[] = "  [{$m['id']}] ({$m['type']}) {$m['title']}";
            }
            $ctx->ui->showNotice("Memories:\n".implode("\n", $lines));
        }

        return SlashCommandResult::continue();
    }
}
