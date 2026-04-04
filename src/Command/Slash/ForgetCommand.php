<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Deletes a persisted memory entry by its numeric ID.
 */
class ForgetCommand implements SlashCommand
{
    public function name(): string
    {
        return '/forget';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Delete a memory by ID';
    }

    /** @return bool Whether this command requires an agent turn after execution */
    public function immediate(): bool
    {
        return false;
    }

    /**
     * @param  string  $args  Numeric memory ID to delete
     * @param  SlashCommandContext  $ctx  Current session context
     * @return SlashCommandResult Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $id = (int) trim($args);

        if ($id > 0) {
            $ctx->sessionManager->deleteMemory($id);
            $ctx->ui->showNotice("Memory #{$id} deleted.");
        } else {
            $ctx->ui->showNotice('Usage: /forget <id>');
        }

        return SlashCommandResult::continue();
    }
}
