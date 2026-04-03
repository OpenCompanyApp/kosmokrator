<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM\Codex;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CodexAuthFlowTest extends TestCase
{
    private CodexOAuthService&MockObject $oauth;

    private CodexTokenStore&MockObject $tokens;

    private Repository $config;

    private CodexAuthFlow $flow;

    protected function setUp(): void
    {
        $this->oauth = $this->createMock(CodexOAuthService::class);
        $this->tokens = $this->createMock(CodexTokenStore::class);
        $this->config = new Repository;

        $this->flow = new CodexAuthFlow(
            $this->oauth,
            $this->tokens,
            $this->config,
        );
    }

    public function testCurrentReturnsNullWhenStoreHasNoToken(): void
    {
        $this->tokens->method('current')->willReturn(null);

        $this->assertNull($this->flow->current());
    }

    public function testCurrentReturnsTokenWhenStoreHasOne(): void
    {
        $token = new CodexToken(
            accessToken: 'access-123',
            refreshToken: 'refresh-456',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );

        $this->tokens->method('current')->willReturn($token);

        $result = $this->flow->current();

        $this->assertSame($token, $result);
        $this->assertSame('access-123', $result->accessToken);
    }

    public function testLogoutCallsStoreClear(): void
    {
        $this->tokens->expects($this->once())->method('clear');

        $this->flow->logout();
    }

    public function testConstructorAcceptsExpectedDependencies(): void
    {
        $flow = new CodexAuthFlow(
            $this->oauth,
            $this->tokens,
            $this->config,
        );

        // Verify the object was constructed without error and delegates correctly
        $this->tokens->method('current')->willReturn(null);
        $this->assertNull($flow->current());
    }
}
