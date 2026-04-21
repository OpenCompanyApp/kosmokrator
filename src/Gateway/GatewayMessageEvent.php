<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final readonly class GatewayMessageEvent
{
    public function __construct(
        public int $updateId,
        public string $platform,
        public string $chatId,
        public ?string $threadId,
        public string $routeKey,
        public string $text,
        public ?string $userId,
        public ?string $username,
        public bool $isPrivate,
        public bool $isReplyToBot,
        public bool $mentionsBot,
        public ?int $messageId = null,
        public ?string $callbackQueryId = null,
    ) {}

    public function isCommand(string $name): bool
    {
        return preg_match('/^'.preg_quote($name, '/').'(\s|$)/i', trim($this->text)) === 1;
    }

    public function withRouteKey(string $routeKey): self
    {
        return new self(
            updateId: $this->updateId,
            platform: $this->platform,
            chatId: $this->chatId,
            threadId: $this->threadId,
            routeKey: $routeKey,
            text: $this->text,
            userId: $this->userId,
            username: $this->username,
            isPrivate: $this->isPrivate,
            isReplyToBot: $this->isReplyToBot,
            mentionsBot: $this->mentionsBot,
            messageId: $this->messageId,
            callbackQueryId: $this->callbackQueryId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'update_id' => $this->updateId,
            'platform' => $this->platform,
            'chat_id' => $this->chatId,
            'thread_id' => $this->threadId,
            'route_key' => $this->routeKey,
            'text' => $this->text,
            'user_id' => $this->userId,
            'username' => $this->username,
            'is_private' => $this->isPrivate,
            'is_reply_to_bot' => $this->isReplyToBot,
            'mentions_bot' => $this->mentionsBot,
            'message_id' => $this->messageId,
            'callback_query_id' => $this->callbackQueryId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            updateId: (int) ($payload['update_id'] ?? 0),
            platform: (string) ($payload['platform'] ?? 'telegram'),
            chatId: (string) ($payload['chat_id'] ?? ''),
            threadId: isset($payload['thread_id']) ? (string) $payload['thread_id'] : null,
            routeKey: (string) ($payload['route_key'] ?? ''),
            text: (string) ($payload['text'] ?? ''),
            userId: isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            username: isset($payload['username']) ? (string) $payload['username'] : null,
            isPrivate: (bool) ($payload['is_private'] ?? false),
            isReplyToBot: (bool) ($payload['is_reply_to_bot'] ?? false),
            mentionsBot: (bool) ($payload['mentions_bot'] ?? false),
            messageId: isset($payload['message_id']) ? (int) $payload['message_id'] : null,
            callbackQueryId: isset($payload['callback_query_id']) ? (string) $payload['callback_query_id'] : null,
        );
    }
}
