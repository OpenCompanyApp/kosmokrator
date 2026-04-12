<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Illuminate\Config\Repository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;

final readonly class TelegramGatewayConfig
{
    /**
     * @param  list<string>  $allowedUsers
     * @param  list<string>  $allowedChats
     * @param  list<string>  $freeResponseChats
     */
    public function __construct(
        public bool $enabled,
        public string $token,
        public string $sessionMode,
        public array $allowedUsers,
        public array $allowedChats,
        public bool $requireMention,
        public array $freeResponseChats,
        public int $pollTimeoutSeconds,
    ) {}

    public static function fromSettings(SettingsManager $settings, Repository $config, ?SettingsRepositoryInterface $repository = null): self
    {
        $enabled = self::toBool(($repository?->get('global', 'kosmokrator.gateway.telegram.enabled'))
            ?? $settings->getRaw('kosmokrator.gateway.telegram.enabled')
            ?? $config->get('kosmokrator.gateway.telegram.enabled', false));

        $token = trim((string) (
            $repository?->get('global', 'kosmokrator.gateway.telegram.token')
            ?? $settings->getRaw('kosmokrator.gateway.telegram.token')
            ?? $config->get('kosmokrator.gateway.telegram.token', '')
            ?? getenv('KOSMOKRATOR_TELEGRAM_BOT_TOKEN')
            ?: ''
        ));

        return new self(
            enabled: $enabled,
            token: $token,
            sessionMode: (string) (
                ($repository?->get('global', 'kosmokrator.gateway.telegram.session_mode'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.session_mode')
                ?? $config->get('kosmokrator.gateway.telegram.session_mode', 'thread')
            ),
            allowedUsers: self::toList(
                ($repository?->get('global', 'kosmokrator.gateway.telegram.allowed_users'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.allowed_users')
                ?? $config->get('kosmokrator.gateway.telegram.allowed_users', [])
            ),
            allowedChats: self::toList(
                ($repository?->get('global', 'kosmokrator.gateway.telegram.allowed_chats'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.allowed_chats')
                ?? $config->get('kosmokrator.gateway.telegram.allowed_chats', [])
            ),
            requireMention: self::toBool(
                ($repository?->get('global', 'kosmokrator.gateway.telegram.require_mention'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.require_mention')
                ?? $config->get('kosmokrator.gateway.telegram.require_mention', true)
            ),
            freeResponseChats: self::toList(
                ($repository?->get('global', 'kosmokrator.gateway.telegram.free_response_chats'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.free_response_chats')
                ?? $config->get('kosmokrator.gateway.telegram.free_response_chats', [])
            ),
            pollTimeoutSeconds: max(1, (int) (
                ($repository?->get('global', 'kosmokrator.gateway.telegram.poll_timeout_seconds'))
                ?? $settings->getRaw('kosmokrator.gateway.telegram.poll_timeout_seconds')
                ?? $config->get('kosmokrator.gateway.telegram.poll_timeout_seconds', 20)
            )),
        );
    }

    public function validate(): void
    {
        if (! $this->enabled) {
            throw new \RuntimeException('Telegram gateway is disabled. Set kosmokrator.gateway.telegram.enabled to true.');
        }

        if ($this->token === '') {
            throw new \RuntimeException('Telegram gateway token is not configured. Set kosmokrator.gateway.telegram.token or KOSMOKRATOR_TELEGRAM_BOT_TOKEN.');
        }

        if (! in_array($this->sessionMode, ['chat', 'chat_user', 'thread', 'thread_user'], true)) {
            throw new \RuntimeException('Telegram gateway session mode must be one of: chat, chat_user, thread, thread_user.');
        }
    }

    public function allowsChat(string $chatId): bool
    {
        return $this->allowedChats === [] || in_array($chatId, $this->allowedChats, true);
    }

    public function allowsUser(?string $userId, ?string $username): bool
    {
        if ($this->allowedUsers === []) {
            return true;
        }

        if ($userId !== null && in_array($userId, $this->allowedUsers, true)) {
            return true;
        }

        return $username !== null && $username !== '' && in_array(ltrim($username, '@'), $this->allowedUsers, true);
    }

    public function isFreeResponseChat(string $chatId): bool
    {
        return in_array($chatId, $this->freeResponseChats, true);
    }

    /**
     * @return list<string>
     */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            $items = array_map(static fn ($item): string => trim((string) $item), $value);

            return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        }

        if (! is_string($value)) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $value) ?: [];
        $items = array_map(static fn ($item): string => trim((string) $item), $parts);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
