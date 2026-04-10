<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentMode;
use PHPUnit\Framework\TestCase;

class AgentModeTest extends TestCase
{
    public function test_edit_mode_has_all_tools(): void
    {
        $tools = AgentMode::Edit->allowedTools();

        $this->assertContains('file_read', $tools);
        $this->assertContains('file_write', $tools);
        $this->assertContains('file_edit', $tools);
        $this->assertContains('apply_patch', $tools);
        $this->assertContains('glob', $tools);
        $this->assertContains('grep', $tools);
        $this->assertContains('bash', $tools);
        $this->assertContains('shell_start', $tools);
        $this->assertContains('shell_write', $tools);
        $this->assertContains('shell_read', $tools);
        $this->assertContains('shell_kill', $tools);
        $this->assertContains('task_create', $tools);
        $this->assertContains('task_update', $tools);
        $this->assertContains('task_list', $tools);
        $this->assertContains('task_get', $tools);
        $this->assertContains('ask_user', $tools);
        $this->assertContains('ask_choice', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertContains('session_search', $tools);
        $this->assertContains('memory_save', $tools);
        $this->assertContains('subagent', $tools);
        $this->assertCount(25, $tools);
    }

    public function test_plan_mode_has_read_only_tools(): void
    {
        $tools = AgentMode::Plan->allowedTools();

        $this->assertContains('file_read', $tools);
        $this->assertContains('glob', $tools);
        $this->assertContains('grep', $tools);
        $this->assertContains('shell_start', $tools);
        $this->assertContains('shell_write', $tools);
        $this->assertContains('shell_read', $tools);
        $this->assertContains('shell_kill', $tools);
        $this->assertContains('subagent', $tools);
        $this->assertContains('task_create', $tools);
        $this->assertContains('task_update', $tools);
        $this->assertContains('task_list', $tools);
        $this->assertContains('task_get', $tools);
        $this->assertContains('ask_user', $tools);
        $this->assertContains('ask_choice', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertContains('session_search', $tools);
        $this->assertNotContains('file_write', $tools);
        $this->assertNotContains('file_edit', $tools);
        $this->assertNotContains('memory_save', $tools);
        $this->assertContains('bash', $tools);
    }

    public function test_ask_mode_has_read_only_tools_plus_bash(): void
    {
        $tools = AgentMode::Ask->allowedTools();

        $this->assertContains('file_read', $tools);
        $this->assertContains('glob', $tools);
        $this->assertContains('grep', $tools);
        $this->assertContains('bash', $tools);
        $this->assertContains('shell_start', $tools);
        $this->assertContains('shell_write', $tools);
        $this->assertContains('shell_read', $tools);
        $this->assertContains('shell_kill', $tools);
        $this->assertContains('task_create', $tools);
        $this->assertContains('task_update', $tools);
        $this->assertContains('task_list', $tools);
        $this->assertContains('task_get', $tools);
        $this->assertContains('ask_user', $tools);
        $this->assertContains('ask_choice', $tools);
        $this->assertContains('memory_search', $tools);
        $this->assertContains('session_search', $tools);
        $this->assertNotContains('file_write', $tools);
        $this->assertNotContains('file_edit', $tools);
        $this->assertNotContains('memory_save', $tools);
    }

    public function test_each_mode_has_label(): void
    {
        foreach (AgentMode::cases() as $mode) {
            $this->assertNotEmpty($mode->label());
        }
    }

    public function test_edit_mode_has_prompt_suffix(): void
    {
        $suffix = AgentMode::Edit->systemPromptSuffix();

        $this->assertStringContainsString('Edit', $suffix);
        $this->assertStringContainsString('full access', $suffix);
    }

    public function test_plan_mode_has_prompt_suffix(): void
    {
        $suffix = AgentMode::Plan->systemPromptSuffix();

        $this->assertStringContainsString('Plan', $suffix);
        $this->assertStringContainsString('READ-ONLY', $suffix);
        $this->assertStringContainsString('STRICTLY FORBIDDEN', $suffix);
        $this->assertStringContainsString('Architecture diagrams', $suffix);
    }

    public function test_ask_mode_has_prompt_suffix(): void
    {
        $suffix = AgentMode::Ask->systemPromptSuffix();

        $this->assertStringContainsString('Ask', $suffix);
        $this->assertStringContainsString('READ-ONLY', $suffix);
        $this->assertStringContainsString('concisely', $suffix);
    }

    public function test_modes_from_string(): void
    {
        $this->assertSame(AgentMode::Edit, AgentMode::from('edit'));
        $this->assertSame(AgentMode::Plan, AgentMode::from('plan'));
        $this->assertSame(AgentMode::Ask, AgentMode::from('ask'));
    }
}
