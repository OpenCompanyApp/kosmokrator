<?php

namespace Kosmokrator\Tests\Feature;

use Kosmokrator\Command\AgentCommand;
use Kosmokrator\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AgentCommandTest extends TestCase
{
    public function test_agent_command_requires_prompt_in_headless_mode(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 2));
        $kernel->boot();

        $command = new AgentCommand($kernel->getContainer());
        $tester = new CommandTester($command);

        $tester->execute(['--no-animation' => true, '--renderer' => 'ansi']);

        $this->assertSame(1, $tester->getStatusCode());
    }
}
