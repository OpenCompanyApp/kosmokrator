<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\AgentLoop;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\UI\NullRenderer;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

/**
 * Integration tests for StuckDetector escalation in headless (subagent) mode.
 * Uses the real StuckDetector via AgentLoop::runHeadless().
 */
class StuckDetectionTest extends IntegrationTestCase
{
    /**
     * Build an AgentLoop suitable for headless testing.
     * Uses NullRenderer (like real subagents) and fresh RecordingLlmClient.
     */
    private function createHeadlessLoop(array $tools = []): AgentLoop
    {
        $loop = new AgentLoop(
            llm: $this->llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'You are a test assistant.',
        );

        if ($tools !== []) {
            $loop->setTools($tools);
        }

        $loop->setStats(new SubagentStats('test-agent'));

        return $loop;
    }

    public function test_normal_completion_without_stuck_detection(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: 'Task completed successfully.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 50,
        ));

        $loop = $this->createHeadlessLoop();
        $result = $loop->runHeadless('Do something');

        $this->assertSame('Task completed successfully.', $result);
        $this->assertSame(1, $this->llm->getCallCount());
    }

    public function test_tool_call_then_completion_no_stuck(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'grep', arguments: ['pattern' => 'hello']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Found the pattern.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 30,
        ));

        $grepTool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "result: {$pattern}");

        $loop = $this->createHeadlessLoop(tools: [$grepTool]);
        $result = $loop->runHeadless('Search for hello');

        $this->assertSame('Found the pattern.', $result);
        $this->assertSame(2, $this->llm->getCallCount());
    }

    public function test_repeated_tool_calls_trigger_force_return(): void
    {
        // The same tool call repeated many times should trigger stuck detection.
        // StuckDetector: window=8, threshold=3, escalation via nudge → final_notice → force_return
        // After 3+ repetitions: nudge (adds system message → another LLM call)
        // After 2 more: final_notice (another LLM call)
        // After 2 more: force_return (returns immediately)
        // We need enough responses to cover the escalation path.

        $sameToolCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: ['pattern' => 'stuck']);
        $stuckResponse = new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [$sameToolCall],
            promptTokens: 50,
            completionTokens: 10,
        );

        // Queue enough stuck responses to trigger force_return
        // (~3 for nudge, ~2 for final_notice, ~2 for force_return = ~7 rounds)
        for ($i = 0; $i < 20; $i++) {
            $this->llm->queueResponse($stuckResponse);
        }

        $grepTool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "result: {$pattern}");

        $loop = $this->createHeadlessLoop(tools: [$grepTool]);
        $result = $loop->runHeadless('Keep searching');

        // Should have been force-returned
        $this->assertStringContainsString('forced return', $result);
        $this->assertStringContainsString('did not converge', $result);
    }

    public function test_diverse_tool_calls_do_not_trigger_stuck(): void
    {
        // Different tool calls each time should not trigger stuck detection
        $diverseCalls = [
            new ToolCall(id: 'tc_1', name: 'grep', arguments: ['pattern' => 'a']),
            new ToolCall(id: 'tc_2', name: 'grep', arguments: ['pattern' => 'b']),
            new ToolCall(id: 'tc_3', name: 'grep', arguments: ['pattern' => 'c']),
            new ToolCall(id: 'tc_4', name: 'grep', arguments: ['pattern' => 'd']),
        ];

        foreach ($diverseCalls as $call) {
            $this->llm->queueResponse(new LlmResponse(
                text: '',
                finishReason: FinishReason::ToolCalls,
                toolCalls: [$call],
                promptTokens: 50,
                completionTokens: 10,
            ));
        }

        $this->llm->queueResponse(new LlmResponse(
            text: 'All searches done.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 20,
        ));

        $grepTool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "result: {$pattern}");

        $loop = $this->createHeadlessLoop(tools: [$grepTool]);
        $result = $loop->runHeadless('Search multiple patterns');

        $this->assertSame('All searches done.', $result);
        $this->assertSame(5, $this->llm->getCallCount()); // 4 tool rounds + 1 final
    }
}
