<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Renames the current session for easy identification later.
 *
 * Usage: /rename My new title
 *        /rename "Title with spaces"
 */
class RenameCommand implements SlashCommand
{
    public function name(): string
    {
        return '/rename';
    }

    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Rename the current session';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $title = trim($args);

        if ($title === '') {
            $ctx->ui->showNotice('Usage: /rename <new title>');

            return SlashCommandResult::continue();
        }

        // Strip surrounding quotes if present
        if (preg_match('/^"(.+)"$/', $title, $matches) || preg_match("/^'(.+)'/", $title, $matches)) {
            $title = $matches[1];
        }

        $ctx->sessionManager->renameSession($title);
        $ctx->ui->showNotice("Session renamed to: {$title}");

        return SlashCommandResult::continue();
    }
}
