<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

class GuardianCommand implements SlashCommand
{
    public function name(): string
    {
        return '/guardian';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Switch to Guardian permission mode';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->permissions->setPermissionMode(PermissionMode::Guardian);
        $ctx->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color());
        $ctx->sessionManager->setSetting('permission_mode', 'guardian');
        $ctx->ui->showNotice('◈ Guardian mode — safe operations auto-approved.');

        return SlashCommandResult::continue();
    }
}
