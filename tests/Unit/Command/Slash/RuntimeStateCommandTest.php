<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\DefersWhileAgentRuns;
use Kosmokrator\Command\Slash\ArgusCommand;
use Kosmokrator\Command\Slash\GuardianCommand;
use Kosmokrator\Command\Slash\ModeCommand;
use Kosmokrator\Command\Slash\PrometheusCommand;
use PHPUnit\Framework\TestCase;

final class RuntimeStateCommandTest extends TestCase
{
    public function test_runtime_state_commands_defer_during_agent_turns(): void
    {
        $commands = [
            new GuardianCommand,
            new ArgusCommand,
            new PrometheusCommand,
            new ModeCommand(AgentMode::Ask),
            new ModeCommand(AgentMode::Edit),
            new ModeCommand(AgentMode::Plan),
        ];

        foreach ($commands as $command) {
            $this->assertInstanceOf(DefersWhileAgentRuns::class, $command);
            $this->assertTrue($command->immediate());
        }
    }
}
