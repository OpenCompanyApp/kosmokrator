<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Lists recent sessions for the current project with status previews.
 */
class SessionsCommand implements SlashCommand
{
    public function name(): string
    {
        return '/sessions';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return [];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'List recent sessions';
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
        $sessions = $ctx->sessionManager->listSessions(10);

        if ($sessions === []) {
            $ctx->ui->showNotice('No sessions found for this project.');
        } else {
            $lines = [];
            foreach ($sessions as $s) {
                $lines[] = self::formatSessionLine($s, $ctx->sessionManager->currentSessionId());
            }
            $ctx->ui->showNotice("Recent sessions:\n".implode("\n", $lines));
        }

        return SlashCommandResult::continue();
    }

    /**
     * Formats a single session row for display, highlighting the active session.
     *
     * @param  array<string, mixed>  $session    Session data from SessionManager
     * @param  string|null           $currentId  ID of the currently active session, if any
     * @return string Formatted line e.g. "a1b2c3d4  Fix the bug  (12 msgs, 5m ago) ←"
     */
    private static function formatSessionLine(array $session, ?string $currentId): string
    {
        $id = substr($session['id'], 0, 8);
        $current = $session['id'] === $currentId ? ' ←' : '';
        $msgCount = $session['message_count'] ?? 0;
        $age = SessionFormatter::formatAge($session['updated_at'] ?? '');
        $preview = $session['last_user_message'] ?? $session['title'] ?? null;

        if ($preview !== null) {
            // Collapse whitespace and truncate to 60 chars for a tidy single-line preview
            $preview = mb_substr(trim(str_replace("\n", ' ', $preview)), 0, 60);
        } else {
            $preview = '(empty)';
        }

        return "  {$id}  {$preview}  ({$msgCount} msgs, {$age}){$current}";
    }
}
