<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\ProtectedContextBuilder;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Task\TaskStore;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Psr\Log\NullLogger;

class ProtectedContextBuilderTest extends TestCase
{
    public function test_constructor_with_null_task_store(): void
    {
        $builder = new ProtectedContextBuilder(null);

        $messages = $builder->build(AgentMode::Edit);
        $this->assertCount(1, $messages);
    }

    public function test_constructor_with_mock_task_store(): void
    {
        $taskStore = $this->createStub(TaskStore::class);
        $builder = new ProtectedContextBuilder($taskStore);

        $messages = $builder->build(AgentMode::Edit);
        $this->assertCount(1, $messages);
    }

    public function test_build_returns_array_with_one_system_message(): void
    {
        $builder = new ProtectedContextBuilder;
        $messages = $builder->build(AgentMode::Edit);

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(SystemMessage::class, $messages[0]);
    }

    public function test_system_message_contains_protected_context_header(): void
    {
        $builder = new ProtectedContextBuilder;
        $messages = $builder->build(AgentMode::Edit);
        $content = $messages[0]->content;

        $this->assertStringContainsString('## Protected Context', $content);
    }

    public function test_system_message_contains_mode_label(): void
    {
        $builder = new ProtectedContextBuilder;

        foreach (AgentMode::cases() as $mode) {
            $messages = $builder->build($mode);
            $this->assertStringContainsString('- Mode: '.$mode->label(), $messages[0]->content);
        }
    }

    public function test_system_message_contains_working_directory(): void
    {
        $builder = new ProtectedContextBuilder;
        $messages = $builder->build(AgentMode::Edit);
        $cwd = getcwd() ?: '.';

        $this->assertStringContainsString('- Working directory: '.$cwd, $messages[0]->content);
    }

    public function test_with_agent_context_contains_type_and_depth(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 5);
        $agentContext = new AgentContext(AgentType::General, 2, 5, $orchestrator, 'test-id', 'test task');

        $builder = new ProtectedContextBuilder;
        $messages = $builder->build(AgentMode::Edit, $agentContext);
        $content = $messages[0]->content;

        $this->assertStringContainsString('- Agent type: general', $content);
        $this->assertStringContainsString('- Agent depth: 2/5', $content);
    }

    public function test_without_agent_context_no_type_or_depth_info(): void
    {
        $builder = new ProtectedContextBuilder;
        $messages = $builder->build(AgentMode::Edit);
        $content = $messages[0]->content;

        $this->assertStringNotContainsString('Agent type:', $content);
        $this->assertStringNotContainsString('Agent depth:', $content);
    }
}
