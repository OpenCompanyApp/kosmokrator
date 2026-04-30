<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\DefersWhileAgentRuns;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

/**
 * Switches the session to Argus permission mode (all writes require approval).
 */
class ArgusCommand implements DefersWhileAgentRuns, SlashCommand
{
    public function name(): string
    {
        return '/argus';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Switch to Argus permission mode';
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
        $ctx->permissions->setPermissionMode(PermissionMode::Argus);
        $ctx->ui->setPermissionMode(PermissionMode::Argus->statusLabel(), PermissionMode::Argus->color());
        $ctx->sessionManager->setSetting('tools.default_permission_mode', 'argus');
        $ctx->ui->showNotice('◉ Argus mode — all write operations require approval.');

        return SlashCommandResult::continue();
    }
}
