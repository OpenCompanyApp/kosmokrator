<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Acp;

use Kosmokrator\Acp\AcpKosmokratorProtocol;
use Kosmokrator\Agent\SubagentStats;
use PHPUnit\Framework\TestCase;

final class AcpKosmokratorProtocolTest extends TestCase
{
    public function test_capabilities_advertise_rich_kosmokrator_surface(): void
    {
        $capabilities = AcpKosmokratorProtocol::capabilities();

        $this->assertSame(1, $capabilities['protocolVersion']);
        $this->assertTrue($capabilities['uiEvents']);
        $this->assertTrue($capabilities['subagentTree']);
        $this->assertTrue($capabilities['integrations']);
        $this->assertTrue($capabilities['mcp']);
        $this->assertTrue($capabilities['lua']);
        $this->assertTrue($capabilities['runtimeConfig']);
    }

    public function test_events_normalize_subagent_stats(): void
    {
        $stats = new SubagentStats('agent-1');
        $stats->status = 'running';
        $stats->task = 'Inspect ACP';
        $stats->agentType = 'explore';
        $stats->toolCalls = 2;

        $event = AcpKosmokratorProtocol::event('s1', 'run_1', 'subagent_status', [
            'stats' => ['agent-1' => $stats],
        ]);

        $this->assertSame('subagent_status', $event['type']);
        $this->assertSame('s1', $event['sessionId']);
        $this->assertSame('run_1', $event['runId']);
        $this->assertSame('running', $event['stats']['agent-1']['status']);
        $this->assertSame('Inspect ACP', $event['stats']['agent-1']['task']);
        $this->assertSame(2, $event['stats']['agent-1']['toolCalls']);
    }
}
