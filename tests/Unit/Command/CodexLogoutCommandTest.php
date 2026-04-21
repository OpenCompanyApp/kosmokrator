<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\CodexLogoutCommand;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class CodexLogoutCommandTest extends TestCase
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
        $app->addCommand(new CodexLogoutCommand($this->container));

        $this->tester = new CommandTester($app->get('codex:logout'));
    }

    public function test_no_tokens_outputs_message(): void
    {
        $this->tokenStore->method('current')->willReturn(null);

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No Codex tokens stored.', $this->tester->getDisplay());
    }

    public function test_has_tokens_clears_and_outputs_message(): void
    {
        $token = new CodexToken(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );

        $this->tokenStore->method('current')->willReturn($token);
        $this->tokenStore->expects($this->once())->method('clear');

        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Codex tokens removed.', $this->tester->getDisplay());
    }
}
