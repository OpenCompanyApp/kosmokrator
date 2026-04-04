<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

/**
 * Switches the session to Guardian permission mode (safe operations auto-approved).
 */
class GuardianCommand implements SlashCommand
{
    public function name(): string
    {
        return '/guardian';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Switch to Guardian permission mode';
    }

    /** @return bool Whether this command executes immediately (no agent turn needed) */
    public function immediate(): bool
    {
        return true;
    }

    /**
     * @param  string  $args  Unused command arguments
     * @param  SlashCommandContext  $ctx  Current session context
     * @return SlashCommandResult Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->permissions->setPermissionMode(PermissionMode::Guardian);
        $ctx->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color());
        $ctx->sessionManager->setSetting('permission_mode', 'guardian');
        $ctx->ui->showNotice('◈ Guardian mode — safe operations auto-approved.');

        return SlashCommandResult::continue();
    }
}
