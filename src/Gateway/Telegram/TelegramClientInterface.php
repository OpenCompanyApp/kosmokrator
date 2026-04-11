<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

interface TelegramClientInterface
{
    /**
     * @param  list<array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands): void;

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(?int $offset, int $timeout): array;

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $chatId, string $text, ?string $threadId = null, ?int $replyToMessageId = null, ?array $replyMarkup = null): array;

    /**
     * @return array<string, mixed>
     */
    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array;

    /**
     * @return array<string, mixed>
     */
    public function sendPhoto(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array;

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array;

    /**
     * @return array<string, mixed>
     */
    public function sendVoice(string $chatId, string $path, ?string $threadId = null, ?string $caption = null): array;

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void;

    public function deleteWebhook(bool $dropPendingUpdates = false): void;
}
