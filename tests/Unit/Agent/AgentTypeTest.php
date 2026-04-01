<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentType;
use PHPUnit\Framework\TestCase;

class AgentTypeTest extends TestCase
{
    public function test_general_can_spawn_all_types(): void
    {
        $allowed = AgentType::General->allowedChildTypes();
        $this->assertContains(AgentType::General, $allowed);
        $this->assertContains(AgentType::Explore, $allowed);
        $this->assertContains(AgentType::Plan, $allowed);
    }

    public function test_explore_can_only_spawn_explore(): void
    {
        $allowed = AgentType::Explore->allowedChildTypes();
        $this->assertEquals([AgentType::Explore], $allowed);
    }

    public function test_plan_can_only_spawn_explore(): void
    {
        $allowed = AgentType::Plan->allowedChildTypes();
        $this->assertEquals([AgentType::Explore], $allowed);
    }

    public function test_general_allowed_tools_includes_write(): void
    {
        $tools = AgentType::General->allowedTools();
        $this->assertContains('file_write', $tools);
        $this->assertContains('file_edit', $tools);
        $this->assertContains('bash', $tools);
        $this->assertContains('subagent', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertContains('memory_save', $tools);
    }

    public function test_explore_allowed_tools_excludes_write(): void
    {
        $tools = AgentType::Explore->allowedTools();
        $this->assertContains('file_read', $tools);
        $this->assertContains('glob', $tools);
        $this->assertContains('grep', $tools);
        $this->assertContains('bash', $tools);
        $this->assertContains('subagent', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertNotContains('file_write', $tools);
        $this->assertNotContains('file_edit', $tools);
        $this->assertNotContains('memory_save', $tools);
    }

    public function test_plan_allowed_tools_excludes_write(): void
    {
        $tools = AgentType::Plan->allowedTools();
        $this->assertNotContains('file_write', $tools);
        $this->assertNotContains('file_edit', $tools);
        $this->assertContains('subagent', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertNotContains('memory_save', $tools);
    }

    public function test_each_type_has_system_prompt_suffix(): void
    {
        foreach (AgentType::cases() as $type) {
            $suffix = $type->systemPromptSuffix();
            $this->assertNotEmpty($suffix);
            $this->assertIsString($suffix);
        }
    }
}
