<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ContextAnalyzer;
use Kosmokrator\Agent\ContextSuggestionService;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

final class ContextAnalyzerTest extends TestCase
{
    public function test_analyzer_groups_prompt_history_and_tool_buckets(): void
    {
        $analyzer = new ContextAnalyzer;
        $prompt = "Stable prompt\n\n# Operational Mode: Edit\nMode text\n\n## Current Tasks\n- Task";
        $messages = [
            new UserMessage('hello'),
            new AssistantMessage('hi'),
            new ToolResultMessage([
                new ToolResult(toolCallId: 'tc1', toolName: 'bash', args: [], result: str_repeat('x', 1200)),
            ]),
        ];

        $breakdown = $analyzer->analyze('provider/model', $prompt, $messages, [], [
            'context_window' => 100_000,
            'effective_window' => 84_000,
        ]);

        $bucketNames = array_map(static fn ($bucket): string => $bucket->name, $breakdown->buckets);

        $this->assertContains('stable_system', $bucketNames);
        $this->assertContains('mode', $bucketNames);
        $this->assertContains('task_tree', $bucketNames);
        $this->assertContains('messages:user', $bucketNames);
        $this->assertContains('tool:bash', $bucketNames);
        $this->assertGreaterThan(0, $breakdown->estimatedTokens);
    }

    public function test_suggestions_flag_large_tool_result(): void
    {
        $analyzer = new ContextAnalyzer;
        $messages = [
            new UserMessage('run tests'),
            new ToolResultMessage([
                new ToolResult(toolCallId: 'tc1', toolName: 'bash', args: [], result: str_repeat('x', 40_000)),
            ]),
        ];

        $breakdown = $analyzer->analyze('provider/model', 'prompt', $messages, [], [
            'context_window' => 100_000,
            'effective_window' => 84_000,
        ]);
        $suggestions = (new ContextSuggestionService)->suggest($breakdown);

        $this->assertNotEmpty(array_filter($suggestions, static fn ($suggestion): bool => $suggestion->code === 'item.large'));
    }
}
