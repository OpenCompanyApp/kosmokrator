<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class SessionsCommand implements SlashCommand
{
    public function name(): string
    {
        return '/sessions';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'List recent sessions';
    }

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
            $ctx->ui->showNotice("Recent sessions:\n" . implode("\n", $lines));
        }

        return SlashCommandResult::continue();
    }

    private static function formatSessionLine(array $session, ?string $currentId): string
    {
        $id = substr($session['id'], 0, 8);
        $current = $session['id'] === $currentId ? ' ←' : '';
        $msgCount = $session['message_count'] ?? 0;
        $age = self::formatAge($session['updated_at'] ?? '');
        $preview = $session['last_user_message'] ?? $session['title'] ?? null;

        if ($preview !== null) {
            $preview = mb_substr(trim(str_replace("\n", ' ', $preview)), 0, 60);
        } else {
            $preview = '(empty)';
        }

        return "  {$id}  {$preview}  ({$msgCount} msgs, {$age}){$current}";
    }

    private static function formatAge(string $timestamp): string
    {
        if ($timestamp === '') {
            return '?';
        }

        $seconds = time() - (int) ((float) $timestamp);

        if ($seconds < 60) { return 'just now'; }
        if ($seconds < 3600) { return (int) ($seconds / 60) . 'm ago'; }
        if ($seconds < 86400) { return (int) ($seconds / 3600) . 'h ago'; }

        return (int) ($seconds / 86400) . 'd ago';
    }
}
