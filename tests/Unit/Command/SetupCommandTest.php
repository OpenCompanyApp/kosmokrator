<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\SetupCommand;
use Kosmokrator\Setup\SetupFlowInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    public function test_command_name_is_setup(): void
    {
        $command = new SetupCommand($this->makeContainer(new FakeSetupFlow(true)));

        $this->assertSame('setup', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new SetupCommand($this->makeContainer(new FakeSetupFlow(true)));

        $this->assertSame(
            'Open setup-focused settings for provider and model configuration',
            $command->getDescription(),
        );
    }

    public function test_setup_command_opens_setup_flow_and_succeeds_when_completed(): void
    {
        $flow = new FakeSetupFlow(true);
        $tester = new CommandTester(new SetupCommand($this->makeContainer($flow)));

        $exitCode = $tester->execute(['--renderer' => 'ansi', '--no-animation' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($flow->opened);
        $this->assertSame('ansi', $flow->rendererPref);
        $this->assertFalse($flow->animated);
        $this->assertFalse($flow->showIntro);
        $this->assertSame(
            'Open settings to configure your default provider, model, and credentials.',
            $flow->notice,
        );
        $this->assertStringContainsString('Setup complete. Run `kosmo` to start.', $tester->getDisplay());
    }

    public function test_setup_command_fails_when_setup_is_incomplete(): void
    {
        $flow = new FakeSetupFlow(false);
        $tester = new CommandTester(new SetupCommand($this->makeContainer($flow)));

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($flow->opened);
        $this->assertStringContainsString(
            'Setup incomplete. Configure a provider before continuing.',
            $tester->getDisplay(),
        );
    }

    private function makeContainer(SetupFlowInterface $flow): Container
    {
        $container = new Container;
        $container->instance(SetupFlowInterface::class, $flow);

        return $container;
    }
}

final class FakeSetupFlow implements SetupFlowInterface
{
    public bool $opened = false;

    public string $rendererPref = 'auto';

    public bool $animated = false;

    public bool $showIntro = false;

    public ?string $notice = null;

    public function __construct(
        private readonly bool $completed,
    ) {}

    public function needsProviderSetup(): bool
    {
        return true;
    }

    public function open(string $rendererPref = 'auto', bool $animated = false, bool $showIntro = false, ?string $notice = null): bool
    {
        $this->opened = true;
        $this->rendererPref = $rendererPref;
        $this->animated = $animated;
        $this->showIntro = $showIntro;
        $this->notice = $notice;

        return $this->completed;
    }
}
