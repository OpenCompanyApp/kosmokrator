<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Lists, deletes, and cleans up sessions for the current project.
 *
 * Subcommands:
 *   /sessions          — List recent sessions
 *   /sessions clean    — Delete sessions older than 30 days (keeps 5 per project)
 *   /sessions clean N  — Delete sessions older than N days
 *   /sessions delete <id> — Delete a specific session by ID or prefix
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
        return 'List, delete, or clean up sessions';
    }

    /** @return bool Whether this command executes immediately (no agent turn needed) */
    public function immediate(): bool
    {
        return true;
    }

    /**
     * @param  string  $args  Subcommand arguments (empty, "clean [N]", or "delete <id>")
     * @param  SlashCommandContext  $ctx  Current session context
     * @return SlashCommandResult Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $trimmed = trim($args);

        if ($trimmed === '') {
            return $this->listSessions($ctx);
        }

        if (str_starts_with($trimmed, 'clean')) {
            $daysArg = trim(substr($trimmed, 5));

            return $this->cleanupSessions($ctx, $daysArg);
        }

        if (str_starts_with($trimmed, 'delete ')) {
            $id = trim(substr($trimmed, 7));

            return $this->deleteSession($ctx, $id);
        }

        $ctx->ui->showNotice('Usage: /sessions [clean [N]] [delete <id>]');

        return SlashCommandResult::continue();
    }

    private function listSessions(SlashCommandContext $ctx): SlashCommandResult
    {
        $sessions = $ctx->sessionManager->listSessions(50);

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

    private function cleanupSessions(SlashCommandContext $ctx, string $daysArg): SlashCommandResult
    {
        $days = is_numeric($daysArg) && (int) $daysArg > 0 ? (int) $daysArg : 30;
        $count = $ctx->sessionManager->cleanupOldSessions($days);

        if ($count === 0) {
            $ctx->ui->showNotice("No sessions older than {$days} days to clean up.");
        } else {
            $ctx->ui->showNotice("Cleaned up {$count} session(s) older than {$days} days.");
        }

        return SlashCommandResult::continue();
    }

    private function deleteSession(SlashCommandContext $ctx, string $id): SlashCommandResult
    {
        if ($id === '') {
            $ctx->ui->showNotice('Usage: /sessions delete <session-id>');

            return SlashCommandResult::continue();
        }

        $deleted = $ctx->sessionManager->deleteSession($id);

        if ($deleted) {
            $shortId = substr($id, 0, 8);
            $ctx->ui->showNotice("Session {$shortId} deleted.");
        } else {
            $ctx->ui->showNotice("Session not found: {$id}");
        }

        return SlashCommandResult::continue();
    }

    /**
     * Formats a single session row for display, highlighting the active session.
     *
     * @param  array<string, mixed>  $session  Session data from SessionManager
     * @param  string|null  $currentId  ID of the currently active session, if any
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
