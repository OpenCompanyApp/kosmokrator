<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

use Kosmokrator\Session\SettingsRepository;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;

/**
 * Persists Codex OAuth tokens via the KosmoKrator SettingsRepository.
 *
 * Implements the CodexTokenStore contract from prism-codex by mapping token fields
 * to individual settings keys under the "provider.codex." namespace. This avoids
 * file-based token storage and keeps credentials in the same encrypted settings
 * backend used for all other provider secrets.
 */
final class SettingsCodexTokenStore implements CodexTokenStore
{
    private const PREFIX = 'provider.codex.';

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Load the current Codex token from settings, or return null if not stored.
     */
    public function current(): ?CodexToken
    {
        $accessToken = $this->settings->get('global', self::PREFIX.'access_token');
        $refreshToken = $this->settings->get('global', self::PREFIX.'refresh_token');
        $expiresAt = $this->settings->get('global', self::PREFIX.'expires_at');

        if ($accessToken === null || $refreshToken === null || $expiresAt === null) {
            return null;
        }

        $tokenData = $this->settings->get('global', self::PREFIX.'token_data');

        return CodexToken::fromArray([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'account_id' => $this->settings->get('global', self::PREFIX.'account_id'),
            'email' => $this->settings->get('global', self::PREFIX.'email'),
            'token_data' => $tokenData !== null ? json_decode($tokenData, true) ?? [] : [],
            'updated_at' => $this->settings->get('global', self::PREFIX.'updated_at'),
        ]);
    }

    /**
     * Persist a Codex token to settings and return a refreshed instance with updated timestamps.
     *
     * @param CodexToken $token The token to persist
     * @return CodexToken A new token instance with the updated_at timestamp applied
     */
    public function save(CodexToken $token): CodexToken
    {
        $now = new \DateTimeImmutable;

        $this->settings->set('global', self::PREFIX.'access_token', $token->accessToken);
        $this->settings->set('global', self::PREFIX.'refresh_token', $token->refreshToken);
        $this->settings->set('global', self::PREFIX.'expires_at', $token->expiresAt->format(DATE_ATOM));

        if ($token->accountId !== null) {
            $this->settings->set('global', self::PREFIX.'account_id', $token->accountId);
        } else {
            $this->settings->delete('global', self::PREFIX.'account_id');
        }

        if ($token->email !== null) {
            $this->settings->set('global', self::PREFIX.'email', $token->email);
        } else {
            $this->settings->delete('global', self::PREFIX.'email');
        }

        if ($token->tokenData !== []) {
            $this->settings->set('global', self::PREFIX.'token_data', json_encode($token->tokenData, JSON_THROW_ON_ERROR));
        } else {
            $this->settings->delete('global', self::PREFIX.'token_data');
        }

        $this->settings->set('global', self::PREFIX.'updated_at', $now->format(DATE_ATOM));

        return CodexToken::fromArray([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'account_id' => $token->accountId,
            'email' => $token->email,
            'token_data' => $token->tokenData,
            'updated_at' => $now,
        ]);
    }

    /** Remove all stored Codex token fields from settings. */
    public function clear(): void
    {
        foreach ([
            'access_token',
            'refresh_token',
            'expires_at',
            'account_id',
            'email',
            'token_data',
            'updated_at',
        ] as $suffix) {
            $this->settings->delete('global', self::PREFIX.$suffix);
        }
    }
}
