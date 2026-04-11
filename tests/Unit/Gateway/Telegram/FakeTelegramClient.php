<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\Telegram\TelegramClientInterface;

final class FakeTelegramClient implements TelegramClientInterface
{
    /** @var list<array{command: string, description: string}> */
    public array $botCommands = [];

    /** @var list<array<string, mixed>> */
    public array $updates = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<array<string, mixed>> */
    public array $edited = [];

    /** @var list<array<string, mixed>> */
    public array $photos = [];

    /** @var list<array<string, mixed>> */
    public array $documents = [];

    /** @var list<array<string, mixed>> */
    public array $voices = [];

    /** @var list<array<string, mixed>> */
    public array $callbackAnswers = [];

    public function __construct() {}

    public function setMyCommands(array $commands): void
    {
        $this->botCommands = $commands;
    }

    public function getMe(): array
    {
        return ['username' => 'kosmokrator_bot'];
    }

    public function getUpdates(?int $offset, int $timeout): array
    {
        $batch = $this->updates;
        $this->updates = [];

        return $batch;
    }

    public function sendMessage(string $chatId, string $text, ?string $threadId = null, ?int $replyToMessageId = null, ?array $replyMarkup = null): array
    {
        $message = [
            'message_id' => count($this->sent) + 1,
            'chat_id' => $chatId,
            'text' => $text,
            'thread_id' => $threadId,
            'reply_to_message_id' => $replyToMessageId,
            'reply_markup' => $replyMarkup,
        ];
        $this->sent[] = $message;

        return ['message_id' => $message['message_id']];
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        $this->edited[] = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ];

        return ['message_id' => $messageId];
    }

    public function sendPhoto(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array
    {
        $this->photos[] = compact('chatId', 'path', 'threadId', 'caption');

        return ['message_id' => count($this->photos) + 100];
    }

    public function sendDocument(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array
    {
        $this->documents[] = compact('chatId', 'path', 'threadId', 'caption');

        return ['message_id' => count($this->documents) + 200];
    }

    public function sendVoice(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array
    {
        $this->voices[] = compact('chatId', 'path', 'threadId', 'caption');

        return ['message_id' => count($this->voices) + 300];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->callbackAnswers[] = compact('callbackQueryId', 'text');
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): void {}
}
