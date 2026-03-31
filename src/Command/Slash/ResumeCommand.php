<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class ResumeCommand implements SlashCommand
{
    public function name(): string
    {
        return '/resume';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Resume a previous session';
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $sessionId = null;

        if ($args === '') {
            // Interactive picker
            $sessions = $ctx->sessionManager->listSessions(10);
            if ($sessions === []) {
                $ctx->ui->showNotice('No sessions to resume.');

                return SlashCommandResult::continue();
            }

            $items = [];
            foreach ($sessions as $s) {
                $current = $s['id'] === $ctx->sessionManager->currentSessionId() ? ' (current)' : '';
                $msgCount = $s['message_count'] ?? 0;
                $age = self::formatAge($s['updated_at'] ?? '');
                $preview = $s['last_user_message'] ?? $s['title'] ?? '(empty)';
                $preview = mb_substr(trim(str_replace("\n", ' ', $preview)), 0, 50);

                $items[] = [
                    'value' => $s['id'],
                    'label' => $preview.$current,
                    'description' => "{$msgCount} msgs, {$age}",
                ];
            }

            $sessionId = $ctx->ui->pickSession($items);
        } else {
            $found = $ctx->sessionManager->findSession($args);
            $sessionId = $found ? $found['id'] : null;
            if ($sessionId === null) {
                $ctx->ui->showNotice("No session found matching '{$args}'.");

                return SlashCommandResult::continue();
            }
        }

        if ($sessionId !== null) {
            $history = $ctx->sessionManager->resumeSession($sessionId);
            $ctx->agentLoop->setHistory($history);
            $ctx->permissions->resetGrants();
            $ctx->ui->clearConversation();
            $ctx->ui->replayHistory($history->messages());
            $session = $ctx->sessionManager->findSession($sessionId);
            $title = $session['title'] ?? '(untitled)';
            $ctx->ui->showNotice("Resumed: {$title} ({$history->count()} messages)");
        }

        return SlashCommandResult::continue();
    }

    private static function formatAge(string $timestamp): string
    {
        if ($timestamp === '') {
            return '?';
        }

        $seconds = time() - (int) ((float) $timestamp);

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return (int) ($seconds / 60).'m ago';
        }
        if ($seconds < 86400) {
            return (int) ($seconds / 3600).'h ago';
        }

        return (int) ($seconds / 86400).'d ago';
    }
}
