<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM\Codex;

use Kosmokrator\LLM\Codex\SettingsCodexTokenStore;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use PHPUnit\Framework\TestCase;

final class SettingsCodexTokenStoreTest extends TestCase
{
    public function test_saves_and_loads_token(): void
    {
        $store = new SettingsCodexTokenStore(new SettingsRepository(new Database(':memory:')));
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $store->save(new CodexToken(
            accessToken: 'access',
            refreshToken: 'refresh',
            expiresAt: $expiresAt,
            accountId: 'acct_123',
            email: 'dev@example.com',
            tokenData: ['id_token' => 'jwt'],
        ));

        $token = $store->current();

        $this->assertNotNull($token);
        $this->assertSame('access', $token->accessToken);
        $this->assertSame('refresh', $token->refreshToken);
        $this->assertSame('acct_123', $token->accountId);
        $this->assertSame('dev@example.com', $token->email);
        $this->assertSame(['id_token' => 'jwt'], $token->tokenData);
        $this->assertSame($expiresAt->format(DATE_ATOM), $token->expiresAt->format(DATE_ATOM));
    }

    public function test_clear_removes_token(): void
    {
        $store = new SettingsCodexTokenStore(new SettingsRepository(new Database(':memory:')));

        $store->save(new CodexToken(
            accessToken: 'access',
            refreshToken: 'refresh',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        ));

        $store->clear();

        $this->assertNull($store->current());
    }
}
