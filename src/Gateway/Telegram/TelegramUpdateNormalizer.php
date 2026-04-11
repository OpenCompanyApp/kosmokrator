<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;

final class TelegramUpdateNormalizer
{
    public function __construct(
        private readonly string $botUsername,
    ) {}

    public function normalize(array $update): ?GatewayMessageEvent
    {
        $message = null;
        $callbackQueryId = null;
        $callbackText = null;
        if (is_array($update['message'] ?? null)) {
            $message = $update['message'];
        } elseif (is_array($update['edited_message'] ?? null)) {
            $message = $update['edited_message'];
        } elseif (is_array($update['callback_query'] ?? null)) {
            $callback = $update['callback_query'];
            $message = is_array($callback['message'] ?? null) ? $callback['message'] : null;
            $callbackQueryId = isset($callback['id']) ? (string) $callback['id'] : null;
            $callbackText = isset($callback['data']) ? trim((string) $callback['data']) : '';
            if (is_array($callback['from'] ?? null) && is_array($message)) {
                $message['from'] = $callback['from'];
            }
        }

        if (! is_array($message)) {
            return null;
        }

        $text = $callbackText;
        if ($text === null) {
            if (! is_string($message['text'] ?? null)) {
                return null;
            }
            $text = trim((string) $message['text']);
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chatId = (string) ($chat['id'] ?? '');
        if ($chatId === '') {
            return null;
        }

        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null;
        $isPrivate = ($chat['type'] ?? '') === 'private';
        $routeKey = $threadId !== null ? "telegram:{$chatId}:{$threadId}" : "telegram:{$chatId}";

        return new GatewayMessageEvent(
            updateId: (int) ($update['update_id'] ?? 0),
            platform: 'telegram',
            chatId: $chatId,
            threadId: $threadId,
            routeKey: $routeKey,
            text: $text,
            userId: isset($from['id']) ? (string) $from['id'] : null,
            username: isset($from['username']) ? (string) $from['username'] : null,
            isPrivate: $isPrivate,
            isReplyToBot: $this->isReplyToBot($message),
            mentionsBot: $this->mentionsBot($message, $text),
            messageId: isset($message['message_id']) ? (int) $message['message_id'] : null,
            callbackQueryId: $callbackQueryId,
        );
    }

    private function isReplyToBot(array $message): bool
    {
        $reply = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;
        $replyFrom = is_array($reply['from'] ?? null) ? $reply['from'] : null;

        return is_array($replyFrom) && (($replyFrom['username'] ?? null) === $this->botUsername);
    }

    private function mentionsBot(array $message, string $text): bool
    {
        $entities = is_array($message['entities'] ?? null) ? $message['entities'] : [];
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            if (($entity['type'] ?? null) !== 'mention') {
                continue;
            }

            $offset = (int) ($entity['offset'] ?? 0);
            $length = (int) ($entity['length'] ?? 0);
            $mention = mb_substr($text, $offset, $length);
            if (strcasecmp(ltrim($mention, '@'), $this->botUsername) === 0) {
                return true;
            }
        }

        return stripos($text, '@'.$this->botUsername) !== false;
    }
}
