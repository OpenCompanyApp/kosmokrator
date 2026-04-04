<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Tool\Coding\SubagentTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubagentToolTest extends TestCase
{
    private SubagentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = new SubagentOrchestrator(new NullLogger, 3);
    }

    private function makeContext(AgentType $type = AgentType::General, int $depth = 0): AgentContext
    {
        return new AgentContext($type, $depth, 3, $this->orchestrator, 'parent', '');
    }

    private function makeTool(AgentContext $ctx): SubagentTool
    {
        return new SubagentTool($ctx, fn ($childCtx, $task) => "executed: {$task}");
    }

    public function test_name(): void
    {
        $tool = $this->makeTool($this->makeContext());
        $this->assertSame('subagent', $tool->name());
    }

    public function test_task_required(): void
    {
        $tool = $this->makeTool($this->makeContext());
        $result = $tool->execute(['task' => '']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('required', $result->output);
    }

    public function test_invalid_type_returns_error(): void
    {
        $tool = $this->makeTool($this->makeContext());
        $result = $tool->execute(['task' => 'test', 'type' => 'invalid']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid agent type', $result->output);
    }

    public function test_rejects_explore_spawning_general(): void
    {
        $tool = $this->makeTool($this->makeContext(AgentType::Explore));
        $result = $tool->execute(['task' => 'test', 'type' => 'general']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Cannot spawn', $result->output);
    }

    public function test_rejects_plan_spawning_general(): void
    {
        $tool = $this->makeTool($this->makeContext(AgentType::Plan));
        $result = $tool->execute(['task' => 'test', 'type' => 'general']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Cannot spawn', $result->output);
    }

    public function test_rejects_plan_spawning_plan(): void
    {
        $tool = $this->makeTool($this->makeContext(AgentType::Plan));
        $result = $tool->execute(['task' => 'test', 'type' => 'plan']);
        $this->assertFalse($result->success);
    }

    public function test_rejects_spawn_at_max_depth(): void
    {
        $ctx = $this->makeContext(AgentType::General, 2); // depth 2, maxDepth 3 → canSpawn = false
        $tool = $this->makeTool($ctx);
        $result = $tool->execute(['task' => 'test', 'type' => 'explore']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Maximum agent depth', $result->output);
    }

    public function test_await_returns_result(): void
    {
        $result = \Amp\async(function () {
            $tool = $this->makeTool($this->makeContext());

            return $tool->execute(['task' => 'find files', 'type' => 'explore', 'mode' => 'await']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('executed: find files', $result->output);
    }

    public function test_background_returns_ack(): void
    {
        $result = \Amp\async(function () {
            $tool = $this->makeTool($this->makeContext());

            return $tool->execute(['task' => 'background work', 'type' => 'explore', 'mode' => 'background']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('spawned in background', $result->output);
    }

    public function test_defaults_type_explore_mode_await(): void
    {
        $result = \Amp\async(function () {
            $tool = $this->makeTool($this->makeContext());

            return $tool->execute(['task' => 'just a task']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('executed: just a task', $result->output);
    }

    public function test_parameters_include_all_fields(): void
    {
        $tool = $this->makeTool($this->makeContext());
        $params = $tool->parameters();
        $this->assertArrayHasKey('task', $params);
        $this->assertArrayHasKey('type', $params);
        $this->assertArrayHasKey('mode', $params);
        $this->assertArrayHasKey('id', $params);
        $this->assertArrayHasKey('depends_on', $params);
        $this->assertArrayHasKey('group', $params);
        $this->assertSame('enum', $params['type']['type']);
        $this->assertSame('array', $params['depends_on']['type']);
    }

    public function test_explore_type_options_only_explore(): void
    {
        $tool = $this->makeTool($this->makeContext(AgentType::Explore));
        $params = $tool->parameters();
        $this->assertSame(['explore'], $params['type']['options']);
    }

    public function test_general_type_options_include_all(): void
    {
        $tool = $this->makeTool($this->makeContext(AgentType::General));
        $params = $tool->parameters();
        $this->assertContains('general', $params['type']['options']);
        $this->assertContains('explore', $params['type']['options']);
        $this->assertContains('plan', $params['type']['options']);
    }
}
