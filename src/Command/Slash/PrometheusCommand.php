<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

class PrometheusCommand implements SlashCommand
{
    public function name(): string
    {
        return '/prometheus';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Switch to Prometheus permission mode';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->ui->playPrometheus();
        $ctx->permissions->setPermissionMode(PermissionMode::Prometheus);
        $ctx->ui->setPermissionMode(PermissionMode::Prometheus->statusLabel(), PermissionMode::Prometheus->color());
        $ctx->sessionManager->setSetting('permission_mode', 'prometheus');
        $ctx->ui->showNotice('⚡ Prometheus unbound — all tools auto-approved.');

        return SlashCommandResult::continue();
    }
}
