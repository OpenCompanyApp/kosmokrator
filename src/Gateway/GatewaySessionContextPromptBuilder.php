<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final class GatewaySessionContextPromptBuilder
{
    public static function build(GatewayMessageEvent $event, ?string $sessionId = null): string
    {
        $lines = [
            '## Gateway Session Context',
            '',
            'You are replying through the Telegram gateway, not the local terminal.',
            'Keep responses concise and suitable for a chat UI.',
            '',
            'Current source:',
            '- Platform: Telegram',
            '- Chat type: '.($event->isPrivate ? 'private chat' : 'group or channel thread'),
            '- Route key: '.$event->routeKey,
            '- Chat ID: '.$event->chatId,
        ];

        if ($event->threadId !== null) {
            $lines[] = '- Thread ID: '.$event->threadId;
        }

        if ($event->userId !== null) {
            $lines[] = '- User ID: '.$event->userId;
        }

        if ($event->username !== null && $event->username !== '') {
            $lines[] = '- Username: @'.ltrim($event->username, '@');
        }

        if ($sessionId !== null && $sessionId !== '') {
            $lines[] = '- Linked Kosmo session: '.$sessionId;
        }

        $lines[] = '';
        $lines[] = 'Gateway notes:';
        $lines[] = '- Inline approval buttons may appear for dangerous operations.';
        $lines[] = '- The user can also reply with /approve, /approve always, /approve guardian, /approve prometheus, /deny, or /cancel.';
        $lines[] = '- Native Telegram attachments can be sent when the final text includes MEDIA:/absolute/path tags.';

        return implode("\n", $lines);
    }
}
