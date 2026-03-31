<?php

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\Coding\SubagentTool;
use Kosmokrator\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ToolRegistryScopedTest extends TestCase
{
    private ToolRegistry $registry;

    private SubagentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry;
        $this->registry->register(new FileReadTool);
        $this->registry->register(new FileWriteTool);
        $this->registry->register(new FileEditTool);
        $this->registry->register(new GlobTool);
        $this->registry->register(new GrepTool);
        $this->registry->register(new BashTool);
        // Simulate root SubagentTool in registry
        $this->orchestrator = new SubagentOrchestrator(new NullLogger, 3);
        $rootCtx = new AgentContext(AgentType::General, 0, 3, $this->orchestrator, 'root', '');
        $this->registry->register(new SubagentTool($rootCtx, fn () => ''));
    }

    private function makeContext(AgentType $type, int $depth = 0): AgentContext
    {
        return new AgentContext($type, $depth, 3, $this->orchestrator, 'child', '');
    }

    public function test_general_keeps_all_tools(): void
    {
        $scoped = $this->registry->scoped($this->makeContext(AgentType::General));
        $names = array_keys($scoped->all());

        $this->assertContains('file_read', $names);
        $this->assertContains('file_write', $names);
        $this->assertContains('file_edit', $names);
        $this->assertContains('bash', $names);
        // subagent is excluded by scoped(), added externally
        $this->assertNotContains('subagent', $names);
    }

    public function test_explore_excludes_write_tools(): void
    {
        $scoped = $this->registry->scoped($this->makeContext(AgentType::Explore));
        $names = array_keys($scoped->all());

        $this->assertContains('file_read', $names);
        $this->assertContains('glob', $names);
        $this->assertContains('grep', $names);
        $this->assertContains('bash', $names);
        $this->assertNotContains('file_write', $names);
        $this->assertNotContains('file_edit', $names);
        $this->assertNotContains('subagent', $names);
    }

    public function test_plan_excludes_write_tools(): void
    {
        $scoped = $this->registry->scoped($this->makeContext(AgentType::Plan));
        $names = array_keys($scoped->all());

        $this->assertNotContains('file_write', $names);
        $this->assertNotContains('file_edit', $names);
    }

    public function test_scoped_returns_new_instance(): void
    {
        $scoped = $this->registry->scoped($this->makeContext(AgentType::Explore));
        $this->assertNotSame($this->registry, $scoped);
    }

    public function test_scoped_does_not_mutate_original(): void
    {
        $originalCount = count($this->registry->all());
        $this->registry->scoped($this->makeContext(AgentType::Explore));
        $this->assertCount($originalCount, $this->registry->all());
    }
}
