<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

class NewCommand implements SlashCommand
{
    public function name(): string
    {
        return '/new';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Clear conversation and start new session';
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->agentLoop->history()->clear();
        $ctx->agentLoop->resetSessionCost();
        $ctx->permissions->resetGrants();
        $ctx->permissions->setPermissionMode(PermissionMode::Guardian);
        $ctx->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color());
        $ctx->ui->clearConversation();
        $modelName = $ctx->llm->getProvider() . '/' . $ctx->llm->getModel();
        $ctx->sessionManager->createSession($modelName);
        $ctx->ui->showNotice('Conversation cleared. New session started.');

        return SlashCommandResult::continue();
    }
}
