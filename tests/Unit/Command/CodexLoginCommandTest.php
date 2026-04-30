<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\CodexLoginCommand;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class CodexLoginCommandTest extends TestCase
{
    private Container&MockObject $container;

    private CodexOAuthService&MockObject $oauth;

    private CodexTokenStore&MockObject $tokenStore;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->oauth = $this->createMock(CodexOAuthService::class);
        $this->tokenStore = $this->createMock(CodexTokenStore::class);

        $authFlow = new CodexAuthFlow(
            $this->oauth,
            $this->tokenStore,
            new Repository(['kosmo' => ['codex' => ['oauth_port' => 9876]]]),
        );

        $this->container = $this->createMock(Container::class);
        $this->container->method('make')
            ->with(CodexAuthFlow::class)
            ->willReturn($authFlow);

        $app = new Application;
        $app->addCommand(new CodexLoginCommand($this->container));

        $this->tester = new CommandTester($app->get('codex:login'));
    }

    public function test_command_name(): void
    {
        $command = new CodexLoginCommand($this->createMock(Container::class));

        $this->assertSame('codex:login', $command->getName());
    }

    public function test_command_description(): void
    {
        $command = new CodexLoginCommand($this->createMock(Container::class));

        $this->assertSame('Authenticate with ChatGPT for the Codex provider', $command->getDescription());
    }

    public function test_has_device_option(): void
    {
        $command = new CodexLoginCommand($this->createMock(Container::class));
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('device'));

        $option = $definition->getOption('device');
        $this->assertFalse($option->acceptValue());
        $this->assertFalse($option->isValueRequired());
        $this->assertSame('Use the device authorization flow', $option->getDescription());
    }

    public function test_browser_flow_error_outputs_error_message(): void
    {
        $this->oauth->method('generatePkce')->willThrowException(
            new \RuntimeException('OAuth service unavailable'),
        );

        $exit = $this->tester->execute([]);

        $this->assertSame(1, $exit);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('OAuth service unavailable', $display);
        $this->assertStringContainsString('codex:login --device', $display);
    }

    public function test_device_flow_error_outputs_error_message(): void
    {
        $this->oauth->method('initiateDeviceAuth')->willThrowException(
            new \RuntimeException('Device auth failed'),
        );

        $exit = $this->tester->execute(['--device' => true]);

        $this->assertSame(1, $exit);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Device auth failed', $display);
    }

    public function test_device_flow_error_does_not_suggest_device_flag(): void
    {
        $this->oauth->method('initiateDeviceAuth')->willThrowException(
            new \RuntimeException('Device auth failed'),
        );

        $this->tester->execute(['--device' => true]);

        $display = $this->tester->getDisplay();
        // The device flow error should NOT suggest using --device
        $this->assertStringNotContainsString('codex:login --device', $display);
    }
}
