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
        private readonly bool $disableLinkPreviews = true,
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
            'disable_web_page_preview' => $this->disableLinkPreviews,
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

        try {
            return $this->request('sendMessage', $payload);
        } catch (\RuntimeException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'message thread not found') || str_contains($message, 'thread not found')) {
                unset($payload['message_thread_id']);

                return $this->request('sendMessage', $payload);
            }

            if (str_contains($message, 'message to be replied not found') || str_contains($message, 'reply message not found')) {
                unset($payload['reply_parameters']);

                return $this->request('sendMessage', $payload);
            }

            throw $e;
        }
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
            'disable_web_page_preview' => $this->disableLinkPreviews,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            return $this->request('editMessageText', $payload);
        } catch (\RuntimeException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'message is not modified')) {
                return ['message_id' => $messageId];
            }

            throw $e;
        }
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

    public function setMessageReaction(string $chatId, int $messageId, string $emoji): void
    {
        $this->request('setMessageReaction', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reaction' => [
                ['type' => 'emoji', 'emoji' => $emoji],
            ],
        ]);
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
        $attempts = 0;
        start:
        $attempts++;

        try {
            $response = $this->requestClient()->asJson()->post($this->baseUrl.'/'.$method, $payload);
            $result = $this->parseResponse($response);
        } catch (\RuntimeException $e) {
            $retryAfter = $this->retryAfterSeconds($e->getMessage());
            if ($attempts < 3 && $retryAfter !== null) {
                usleep((int) max(100_000, $retryAfter * 1_000_000));

                goto start;
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($attempts < 3 && $this->isRetryableConnectionFailure($e)) {
                usleep((int) (250_000 * $attempts));

                goto start;
            }

            throw $e;
        }

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

    private function retryAfterSeconds(string $message): ?float
    {
        $jsonStart = strpos($message, '{');
        if ($jsonStart !== false) {
            $payload = json_decode(substr($message, $jsonStart), true);
            if (is_array($payload) && isset($payload['parameters']['retry_after'])) {
                return max(0.1, (float) $payload['parameters']['retry_after']);
            }
        }

        if (preg_match('/retry after\s+(\d+)/i', $message, $matches) === 1) {
            return max(0.1, (float) $matches[1]);
        }

        return null;
    }

    private function isRetryableConnectionFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'connect')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'network is unreachable');
    }
}
