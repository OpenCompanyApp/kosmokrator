<?php

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\Tool\MemorySaveTool;
use Kosmokrator\Session\Tool\MemorySearchTool;
use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;
use Kosmokrator\Tool\Coding\ShellKillTool;
use Kosmokrator\Tool\Coding\ShellReadTool;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellStartTool;
use Kosmokrator\Tool\Coding\ShellWriteTool;
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
        $this->registry->register(new ApplyPatchTool(new PatchParser, new PatchApplier));
        $this->registry->register(new GlobTool);
        $this->registry->register(new GrepTool);
        $this->registry->register(new BashTool);
        $shells = new ShellSessionManager(new NullLogger, 10, 5, 5);
        $this->registry->register(new ShellStartTool($shells));
        $this->registry->register(new ShellWriteTool($shells));
        $this->registry->register(new ShellReadTool($shells));
        $this->registry->register(new ShellKillTool($shells));
        $sessionManager = new SessionManager(
            $this->createStub(SessionRepository::class),
            $this->createStub(MessageRepository::class),
            $this->createStub(SettingsRepository::class),
            $this->createStub(MemoryRepository::class),
            new NullLogger,
        );
        $this->registry->register(new MemorySaveTool($sessionManager));
        $this->registry->register(new MemorySearchTool($sessionManager));
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
        $this->assertContains('apply_patch', $names);
        $this->assertContains('bash', $names);
        $this->assertContains('shell_start', $names);
        $this->assertContains('shell_write', $names);
        $this->assertContains('shell_read', $names);
        $this->assertContains('shell_kill', $names);
        $this->assertContains('memory_save', $names);
        $this->assertContains('memory_search', $names);
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
        $this->assertContains('shell_start', $names);
        $this->assertContains('shell_write', $names);
        $this->assertContains('shell_read', $names);
        $this->assertContains('shell_kill', $names);
        $this->assertContains('memory_search', $names);
        $this->assertNotContains('apply_patch', $names);
        $this->assertNotContains('file_write', $names);
        $this->assertNotContains('file_edit', $names);
        $this->assertNotContains('memory_save', $names);
        $this->assertNotContains('subagent', $names);
    }

    public function test_plan_excludes_write_tools(): void
    {
        $scoped = $this->registry->scoped($this->makeContext(AgentType::Plan));
        $names = array_keys($scoped->all());

        $this->assertNotContains('file_write', $names);
        $this->assertNotContains('file_edit', $names);
        $this->assertNotContains('apply_patch', $names);
        $this->assertContains('shell_start', $names);
        $this->assertContains('shell_write', $names);
        $this->assertContains('shell_read', $names);
        $this->assertContains('shell_kill', $names);
        $this->assertContains('memory_search', $names);
        $this->assertNotContains('memory_save', $names);
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
