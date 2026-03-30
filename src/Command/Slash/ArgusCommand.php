<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

class ArgusCommand implements SlashCommand
{
    public function name(): string
    {
        return '/argus';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Switch to Argus permission mode';
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->permissions->setPermissionMode(PermissionMode::Argus);
        $ctx->ui->setPermissionMode(PermissionMode::Argus->statusLabel(), PermissionMode::Argus->color());
        $ctx->sessionManager->setSetting('permission_mode', 'argus');
        $ctx->ui->showNotice('◉ Argus mode — all write operations require approval.');

        return SlashCommandResult::continue();
    }
}
