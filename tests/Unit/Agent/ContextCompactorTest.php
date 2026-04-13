<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ContextCompactorTest extends TestCase
{
    private function makeCompactor(?LlmClientInterface $llm = null, int $thresholdPercent = 60): ContextCompactor
    {
        $llm ??= $this->createMockLlm('Mocked summary');
        $models = new ModelCatalog(['models' => [], 'default' => ['context' => 128_000, 'input_price' => 3.0, 'output_price' => 15.0]]);

        return new ContextCompactor($llm, $models, new NullLogger, $thresholdPercent);
    }

    private function createMockLlm(string $responseText): LlmClientInterface
    {
        $mock = $this->createMock(LlmClientInterface::class);
        $mock->method('chat')->willReturn(new LlmResponse(
            text: $responseText,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 50,
        ));

        return $mock;
    }

    public function test_needs_compaction_true_when_over_threshold(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 60);

        // 128K context * 60% = 76,800 threshold
        $this->assertTrue($compactor->needsCompaction(80_000, 'test-model'));
    }

    public function test_needs_compaction_false_when_under_threshold(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 60);

        $this->assertFalse($compactor->needsCompaction(50_000, 'test-model'));
    }

    public function test_needs_compaction_at_exact_threshold(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 60);

        // Exactly at threshold: 128K * 0.6 = 76,800
        $this->assertTrue($compactor->needsCompaction(76_800, 'test-model'));
    }

    public function test_needs_compaction_custom_threshold(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 80);

        // 128K * 80% = 102,400
        $this->assertFalse($compactor->needsCompaction(100_000, 'test-model'));
        $this->assertTrue($compactor->needsCompaction(102_400, 'test-model'));
    }

    public function test_get_threshold_tokens(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 60);

        // 128K * 60% = 76,800
        $this->assertSame(76_800, $compactor->getThresholdTokens('test-model'));
    }

    public function test_set_compact_threshold_percent(): void
    {
        $compactor = $this->makeCompactor(thresholdPercent: 60);

        $this->assertSame(60, $compactor->getCompactThresholdPercent());

        $compactor->setCompactThresholdPercent(40);
        $this->assertSame(40, $compactor->getCompactThresholdPercent());
        // 128K * 40% = 51,200
        $this->assertSame(51_200, $compactor->getThresholdTokens('test-model'));
    }

    public function test_compact_returns_summary_and_tokens(): void
    {
        $compactor = $this->makeCompactor();

        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');
        $history->addUser('Third question');
        $history->addAssistant('Third answer');
        $history->addUser('Fourth question');
        $history->addAssistant('Fourth answer');

        $result = $compactor->compact($history, 2);

        $this->assertSame('Mocked summary', $result['summary']);
        $this->assertSame(100, $result['tokens_in']);
        $this->assertSame(50, $result['tokens_out']);
    }

    public function test_compact_returns_empty_when_too_few_messages(): void
    {
        $compactor = $this->makeCompactor();

        $history = new ConversationHistory;
        $history->addUser('Only question');

        $result = $compactor->compact($history, 3);

        $this->assertSame('', $result['summary']);
        $this->assertSame(0, $result['tokens_in']);
    }

    public function test_compact_calls_llm_with_formatted_messages(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages) {
                // Should have system + user message
                $this->assertCount(2, $messages);
                $userContent = $messages[1]->content;
                // Should contain formatted old messages
                $this->assertStringContainsString('[user]: Hello', $userContent);
                $this->assertStringContainsString('[assistant]: World', $userContent);

                return true;
            }))
            ->willReturn(new LlmResponse(
                text: 'Summary',
                finishReason: FinishReason::Stop,
                toolCalls: [],
                promptTokens: 100,
                completionTokens: 50,
            ));

        $models = new ModelCatalog(['models' => [], 'default' => ['context' => 128_000, 'input_price' => 3.0, 'output_price' => 15.0]]);
        $compactor = new ContextCompactor($llm, $models, new NullLogger);

        $history = new ConversationHistory;
        $history->addUser('Hello');
        $history->addAssistant('World');
        $history->addUser('Recent');
        $history->addAssistant('Recent answer');

        $compactor->compact($history, 1);
    }

    public function test_build_plan_extracts_memories_from_structured_response(): void
    {
        $json = json_encode([
            'summary' => '## Goal'."\n".'Keep context',
            'memories' => [
                ['type' => 'project', 'title' => 'Uses JWT', 'content' => 'Auth uses JWT tokens'],
                ['type' => 'user', 'title' => 'Prefers tabs', 'content' => 'User prefers tab indentation', 'memory_class' => 'priority', 'pinned' => true],
                ['type' => 'decision', 'title' => 'Pending cleanup', 'content' => 'Cleanup remains', 'memory_class' => 'working', 'expires_days' => 7],
            ],
        ]);

        $compactor = $this->makeCompactor($this->createMockLlm($json));
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');

        $plan = $compactor->buildPlan($history, keepRecent: 1);

        $this->assertSame("## Goal\nKeep context", $plan->summary);
        $this->assertCount(3, $plan->extractedMemories);
        $this->assertSame('project', $plan->extractedMemories[0]['type']);
        $this->assertSame('priority', $plan->extractedMemories[1]['memory_class']);
        $this->assertTrue($plan->extractedMemories[1]['pinned']);
        $this->assertSame(7, $plan->extractedMemories[2]['expires_days']);
        $this->assertSame(100, $plan->tokensIn);
        $this->assertSame(50, $plan->tokensOut);
    }

    public function test_build_plan_falls_back_to_plain_text_summary_on_invalid_json(): void
    {
        $compactor = $this->makeCompactor($this->createMockLlm('not valid json'));
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');

        $plan = $compactor->buildPlan($history, keepRecent: 1);

        $this->assertSame('not valid json', $plan->summary);
        $this->assertSame([], $plan->extractedMemories);
    }

    public function test_build_plan_filters_invalid_memory_items(): void
    {
        $json = json_encode([
            'summary' => 'Summary',
            'memories' => [
                ['type' => 'project', 'title' => 'Valid', 'content' => 'content'],
                ['type' => 'invalid_type', 'title' => 'Bad', 'content' => 'content'],
                ['missing_fields' => true],
                ['type' => 'decision', 'title' => '', 'content' => 'empty title'],
            ],
        ]);

        $compactor = $this->makeCompactor($this->createMockLlm($json));
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');

        $plan = $compactor->buildPlan($history, keepRecent: 1);

        $this->assertCount(1, $plan->extractedMemories);
        $this->assertSame('project', $plan->extractedMemories[0]['type']);
    }

    public function test_build_plan_synthesizes_safe_fallback_summary_when_json_lacks_summary(): void
    {
        $json = json_encode([
            'memories' => [
                ['type' => 'decision', 'title' => 'Use SQLite', 'content' => 'Keep SQLite locally'],
                ['type' => 'project', 'title' => 'Auth refactor', 'content' => 'Auth code was reworked'],
                ['type' => 'project', 'title' => 'Pending cleanup', 'content' => 'Cleanup remains', 'memory_class' => 'working'],
            ],
        ]);

        $compactor = $this->makeCompactor($this->createMockLlm($json));
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');

        $plan = $compactor->buildPlan($history, keepRecent: 1);

        $this->assertStringContainsString('## Goal', $plan->summary);
        $this->assertStringContainsString('[Compaction summary unavailable]', $plan->summary);
        $this->assertStringContainsString('Use SQLite', $plan->summary);
        $this->assertStringNotContainsString('{"memories"', $plan->summary);
        $this->assertCount(3, $plan->extractedMemories);
    }
}
