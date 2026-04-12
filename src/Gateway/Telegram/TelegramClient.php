<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class TelegramClient implements TelegramClientInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $token,
    ) {
        $this->baseUrl = 'https://api.telegram.org/bot'.$this->token;
    }

    public function setMyCommands(array $commands): void
    {
        $this->request('setMyCommands', ['commands' => $commands]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->request('getMe');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(?int $offset, int $timeout): array
    {
        $payload = ['timeout' => $timeout];
        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        $result = $this->request('getUpdates', $payload);

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $chatId, string $text, ?string $threadId = null, ?int $replyToMessageId = null, ?array $replyMarkup = null, ?string $parseMode = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($threadId !== null) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        if ($replyToMessageId !== null) {
            $payload['reply_parameters'] = ['message_id' => $replyToMessageId];
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        return $this->request('sendMessage', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        return $this->request('editMessageText', $payload);
    }

    public function sendPhoto(string $chatId, string $path, ?string $threadId = null, ?string $caption = null, ?string $parseMode = null): array
    {
        $payload = ['chat_id' => $chatId];
        if ($threadId !== null) {
            $payload['message_thread_id'] = (int) $threadId;
        }
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $payload['photo'] = $path;

            return $this->request('sendPhoto', $payload);
        }

        return $this->upload('sendPhoto', 'photo', $path, $payload);
    }

    public function sendDocument(string $chatId, string $path, ?string $threadId = null, ?string $caption = null, ?string $parseMode = null): array
    {
        $payload = ['chat_id' => $chatId];
        if ($threadId !== null) {
            $payload['message_thread_id'] = (int) $threadId;
        }
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $payload['document'] = $path;

            return $this->request('sendDocument', $payload);
        }

        return $this->upload('sendDocument', 'document', $path, $payload);
    }

    public function sendVoice(string $chatId, string $path, ?string $threadId = null, ?string $caption = null, ?string $parseMode = null): array
    {
        $payload = ['chat_id' => $chatId];
        if ($threadId !== null) {
            $payload['message_thread_id'] = (int) $threadId;
        }
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        return $this->upload('sendVoice', 'voice', $path, $payload);
    }

    public function sendChatAction(string $chatId, string $action = 'typing', ?string $threadId = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'action' => $action,
        ];

        if ($threadId !== null) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $this->request('sendChatAction', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        $this->request('answerCallbackQuery', $payload);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): void
    {
        $this->request('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function request(string $method, array $payload = []): array
    {
        $response = $this->requestClient()->asJson()->post($this->baseUrl.'/'.$method, $payload);
        $result = $this->parseResponse($response);

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upload(string $method, string $field, string $path, array $payload): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Telegram media file not found: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open Telegram media file: {$path}");
        }

        try {
            $response = $this->requestClient()
                ->attach($field, $handle, basename($path))
                ->post($this->baseUrl.'/'.$method, $payload);

            $result = $this->parseResponse($response);

            return is_array($result) ? $result : [];
        } finally {
            fclose($handle);
        }
    }

    private function requestClient(): PendingRequest
    {
        return $this->http->timeout(90);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function parseResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new \RuntimeException("Telegram API error ({$response->status()}): ".$response->body());
        }

        $json = $response->json();
        if (! is_array($json) || ! ($json['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API returned an invalid payload.');
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [];
    }
}
