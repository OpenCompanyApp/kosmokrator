<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

class CodexOAuthService
{
    public const ISSUER = 'https://auth.openai.com';

    public const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public const SCOPES = 'openid profile email offline_access';

    public const DEVICE_AUTH_REDIRECT_URI = 'https://auth.openai.com/deviceauth/callback';

    public function __construct(
        private readonly CodexTokenStore $tokens,
        private readonly HttpFactory $http,
        private readonly ?Repository $config = null,
    ) {}

    /** @return array{verifier: string, challenge: string} */
    public function generatePkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    public function buildAuthorizationUrl(string $challenge, string $state, string $redirectUri): string
    {
        $params = [
            'client_id' => self::CLIENT_ID,
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'codex_cli_simplified_flow' => 'true',
        ];

        if ((bool) $this->configValue('codex.id_token_add_organizations', true)) {
            $params['id_token_add_organizations'] = 'true';
        }

        $originator = trim((string) $this->configValue('codex.originator', 'kosmo'));
        if ($originator !== '') {
            $params['originator'] = $originator;
        }

        return self::ISSUER.'/oauth/authorize?'.http_build_query($params);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, id_token?: string} */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        $response = $this->authClient()->asForm()->post(self::ISSUER.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);

        $response->throw();

        return $response->json();
    }

    /** @return array{device_auth_id: string, user_code: string, interval: int} */
    public function initiateDeviceAuth(): array
    {
        $response = $this->authClient()->asJson()->post(self::ISSUER.'/api/accounts/deviceauth/usercode', [
            'client_id' => self::CLIENT_ID,
        ]);

        $response->throw();

        return $response->json();
    }

    /** @return array<string, mixed>|null */
    public function pollDeviceAuth(string $deviceAuthId, string $userCode): ?array
    {
        $response = $this->authClient()->asJson()->post(self::ISSUER.'/api/accounts/deviceauth/token', [
            'device_auth_id' => $deviceAuthId,
            'user_code' => $userCode,
        ]);

        if ($response->status() === 403) {
            return null;
        }

        $response->throw();
        $data = $response->json();

        if (isset($data['authorization_code'], $data['code_verifier'])) {
            return $this->exchangeCode($data['authorization_code'], $data['code_verifier'], self::DEVICE_AUTH_REDIRECT_URI);
        }

        return $data;
    }

    public function refreshToken(): bool
    {
        $stored = $this->tokens->current();
        if (! $stored) {
            return false;
        }

        $response = $this->authClient()->asForm()->post(self::ISSUER.'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $stored->refreshToken,
            'client_id' => self::CLIENT_ID,
        ]);

        if ($response->failed()) {
            return false;
        }

        $data = $response->json();
        $accountId = $this->extractAccountIdFromJwt($data['access_token'] ?? '')
            ?? $this->extractAccountIdFromJwt($data['id_token'] ?? '')
            ?? $stored->accountId;

        $this->storeTokens([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $stored->refreshToken,
            'expires_at' => (new \DateTimeImmutable)->modify('+'.((int) ($data['expires_in'] ?? 3600)).' seconds'),
            'account_id' => $accountId,
        ]);

        return true;
    }

    public function getAccessToken(): ?string
    {
        $stored = $this->tokens->current();
        if (! $stored) {
            return null;
        }

        if ($stored->isExpiringSoon() && ! $this->refreshToken()) {
            return null;
        }

        return $this->tokens->current()?->accessToken;
    }

    public function getAccountId(): ?string
    {
        return $this->tokens->current()?->accountId;
    }

    public function isConfigured(): bool
    {
        return $this->tokens->current() !== null;
    }

    /** @param array<string, mixed> $data */
    public function storeTokens(array $data): CodexToken
    {
        $accessToken = (string) $data['access_token'];
        $accountId = $data['account_id']
            ?? $this->extractAccountIdFromJwt($accessToken)
            ?? $this->extractAccountIdFromJwt((string) ($data['id_token'] ?? ''));
        $email = $this->extractEmailFromJwt((string) ($data['id_token'] ?? ''))
            ?? $this->extractEmailFromJwt($accessToken);
        $expiresAt = $data['expires_at'] ?? (new \DateTimeImmutable)->modify('+'.((int) ($data['expires_in'] ?? 3600)).' seconds');

        return $this->tokens->save(new CodexToken(
            accessToken: $accessToken,
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            expiresAt: $expiresAt instanceof \DateTimeImmutable ? $expiresAt : new \DateTimeImmutable((string) $expiresAt),
            accountId: is_string($accountId) && $accountId !== '' ? $accountId : null,
            email: $email,
            tokenData: array_filter(['id_token' => $data['id_token'] ?? null]),
        ));
    }

    public function extractAccountIdFromJwt(string $jwt): ?string
    {
        $claims = $this->decodeJwtClaims($jwt);
        if (! $claims) {
            return null;
        }

        if (! empty($claims['chatgpt_account_id'])) {
            return (string) $claims['chatgpt_account_id'];
        }

        $namespaced = $claims['https://api.openai.com/auth'] ?? null;
        if (is_array($namespaced) && ! empty($namespaced['chatgpt_account_id'])) {
            return (string) $namespaced['chatgpt_account_id'];
        }

        $orgs = $claims['organizations'] ?? null;

        return is_array($orgs) && ! empty($orgs[0]['id']) ? (string) $orgs[0]['id'] : null;
    }

    public function extractEmailFromJwt(string $jwt): ?string
    {
        $claims = $this->decodeJwtClaims($jwt);

        return isset($claims['email']) ? (string) $claims['email'] : null;
    }

    /** @return array<string, mixed>|null */
    private function decodeJwtClaims(string $jwt): ?array
    {
        if ($jwt === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims = json_decode((string) $payload, true);

        return is_array($claims) ? $claims : null;
    }

    private function authClient(): PendingRequest
    {
        $client = $this->http;
        $userAgent = trim((string) $this->configValue('codex.user_agent', 'kosmo'));

        return $userAgent !== '' ? $client->withUserAgent($userAgent) : $client;
    }

    private function configValue(string $key, mixed $default): mixed
    {
        return $this->config?->get($key, $default) ?? $default;
    }
}
