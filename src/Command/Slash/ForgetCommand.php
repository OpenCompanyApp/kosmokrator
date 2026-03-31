<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class ForgetCommand implements SlashCommand
{
    public function name(): string
    {
        return '/forget';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Delete a memory by ID';
    }

    public function immediate(): bool
    {
        return false;
    }

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
