<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\CodexStatusCommand;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class CodexStatusCommandTest extends TestCase
{
    private Container&MockObject $container;

    private CodexTokenStore&MockObject $tokenStore;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->tokenStore = $this->createMock(CodexTokenStore::class);

        $this->container->method('make')
            ->with(CodexTokenStore::class)
            ->willReturn($this->tokenStore);

        $app = new Application;
        $app->addCommand(new CodexStatusCommand($this->container));

        $this->tester = new CommandTester($app->get('codex:status'));
    }

    public function test_no_token_outputs_not_configured(): void
    {
        $this->tokenStore->method('current')->willReturn(null);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Codex is not configured', $this->tester->getDisplay());
        $this->assertStringContainsString('codex:login', $this->tester->getDisplay());
    }

    public function test_active_token_renders_status_table(): void
    {
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $updatedAt = new \DateTimeImmutable('2025-06-01 12:00:00');

        $token = new CodexToken(
            accessToken: 'at_123',
            refreshToken: 'rt_456',
            expiresAt: $expiresAt,
            accountId: 'acct-789',
            email: 'user@example.com',
            updatedAt: $updatedAt,
        );

        $this->tokenStore->method('current')->willReturn($token);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Active', $display);
        $this->assertStringContainsString('user@example.com', $display);
        $this->assertStringContainsString('acct-789', $display);
        $this->assertStringContainsString($expiresAt->format('Y-m-d H:i:s'), $display);
        $this->assertStringContainsString('Yes', $display);
        $this->assertStringContainsString($updatedAt->format('Y-m-d H:i:s'), $display);
    }

    public function test_expired_token_shows_expired_status(): void
    {
        $token = new CodexToken(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );

        $this->tokenStore->method('current')->willReturn($token);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Expired', $this->tester->getDisplay());
    }

    public function test_expiring_soon_token_shows_needs_refresh(): void
    {
        $token = new CodexToken(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: new \DateTimeImmutable('+10 seconds'),
        );

        $this->tokenStore->method('current')->willReturn($token);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Needs refresh', $this->tester->getDisplay());
    }

    public function test_token_without_optional_fields_shows_na(): void
    {
        $token = new CodexToken(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );

        $this->tokenStore->method('current')->willReturn($token);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('N/A', $display);
    }
}
