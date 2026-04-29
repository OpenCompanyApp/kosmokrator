<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Feature;

use Kosmokrator\Command\AgentCommand;
use Kosmokrator\Kernel;
use Kosmokrator\Setup\SetupFlowInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class AgentCommandTest extends TestCase
{
    public function test_interactive_mode_opens_setup_flow_when_provider_setup_is_missing(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 2));
        $kernel->boot();

        $flow = new class implements SetupFlowInterface
        {
            public bool $opened = false;

            public function needsProviderSetup(): bool
            {
                return true;
            }

            public function open(string $rendererPref = 'auto', bool $animated = false, bool $showIntro = false, ?string $notice = null): bool
            {
                $this->opened = true;

                return false;
            }
        };

        $kernel->getContainer()->instance(SetupFlowInterface::class, $flow);

        $command = new AgentCommand($kernel->getContainer());
        $input = new ArrayInput([
            '--renderer' => 'ansi',
            '--no-animation' => true,
        ]);
        $input->bind($command->getDefinition());

        $invoke = \Closure::bind(
            function (ArrayInput $input, BufferedOutput $output): int {
                return $this->runInteractive($input, $output);
            },
            $command,
            AgentCommand::class,
        );

        $status = $invoke($input, new BufferedOutput);

        $this->assertSame(1, $status);
        $this->assertTrue($flow->opened);
    }

    public function test_agent_command_rejects_invalid_headless_mode_cleanly(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 2));
        $kernel->boot();

        $command = new AgentCommand($kernel->getContainer());
        $tester = new CommandTester($command);

        $tester->execute(['prompt' => 'hello', '--mode' => 'invalid']);

        $this->assertSame(1, $tester->getStatusCode());
    }
}
