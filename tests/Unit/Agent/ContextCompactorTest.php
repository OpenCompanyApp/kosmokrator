<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\Cancellation;
use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Psr\Log\NullLogger;

class ContextCompactorTest extends TestCase
{
    private function makeCompactor(?LlmClientInterface $llm = null, int $buffer = 20_000): ContextCompactor
    {
        $llm ??= $this->createMockLlm('Mocked summary');
        $models = new ModelCatalog(['models' => [], 'default' => ['context' => 128_000, 'input_price' => 3.0, 'output_price' => 15.0]]);

        return new ContextCompactor($llm, $models, new NullLogger(), $buffer);
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
        $compactor = $this->makeCompactor(buffer: 20_000);

        // 128K context - 20K buffer = 108K threshold
        $this->assertTrue($compactor->needsCompaction(110_000, 'test-model'));
    }

    public function test_needs_compaction_false_when_under_threshold(): void
    {
        $compactor = $this->makeCompactor(buffer: 20_000);

        $this->assertFalse($compactor->needsCompaction(50_000, 'test-model'));
    }

    public function test_needs_compaction_at_exact_threshold(): void
    {
        $compactor = $this->makeCompactor(buffer: 20_000);

        // Exactly at threshold: 108K
        $this->assertTrue($compactor->needsCompaction(108_000, 'test-model'));
    }

    public function test_compact_returns_summary_string(): void
    {
        $compactor = $this->makeCompactor();

        $history = new ConversationHistory();
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');
        $history->addUser('Third question');
        $history->addAssistant('Third answer');
        $history->addUser('Fourth question');
        $history->addAssistant('Fourth answer');

        $summary = $compactor->compact($history, 2);

        $this->assertSame('Mocked summary', $summary);
    }

    public function test_compact_returns_empty_when_too_few_messages(): void
    {
        $compactor = $this->makeCompactor();

        $history = new ConversationHistory();
        $history->addUser('Only question');

        $summary = $compactor->compact($history, 3);

        $this->assertSame('', $summary);
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
        $compactor = new ContextCompactor($llm, $models, new NullLogger());

        $history = new ConversationHistory();
        $history->addUser('Hello');
        $history->addAssistant('World');
        $history->addUser('Recent');
        $history->addAssistant('Recent answer');

        $compactor->compact($history, 1);
    }

    public function test_extract_memories_returns_valid_items(): void
    {
        $json = json_encode([
            ['type' => 'project', 'title' => 'Uses JWT', 'content' => 'Auth uses JWT tokens'],
            ['type' => 'user', 'title' => 'Prefers tabs', 'content' => 'User prefers tab indentation'],
        ]);

        $compactor = $this->makeCompactor($this->createMockLlm($json));

        $memories = $compactor->extractMemories('Some summary');

        $this->assertCount(2, $memories);
        $this->assertSame('project', $memories[0]['type']);
        $this->assertSame('user', $memories[1]['type']);
    }

    public function test_extract_memories_returns_empty_on_invalid_json(): void
    {
        $compactor = $this->makeCompactor($this->createMockLlm('not valid json'));

        $memories = $compactor->extractMemories('Some summary');

        $this->assertSame([], $memories);
    }

    public function test_extract_memories_filters_invalid_types(): void
    {
        $json = json_encode([
            ['type' => 'project', 'title' => 'Valid', 'content' => 'content'],
            ['type' => 'invalid_type', 'title' => 'Bad', 'content' => 'content'],
            ['missing_fields' => true],
        ]);

        $compactor = $this->makeCompactor($this->createMockLlm($json));

        $memories = $compactor->extractMemories('Summary');

        $this->assertCount(1, $memories);
        $this->assertSame('project', $memories[0]['type']);
    }

    public function test_extract_memories_returns_empty_on_exception(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chat')->willThrowException(new \RuntimeException('API error'));

        $models = new ModelCatalog(['models' => [], 'default' => ['context' => 128_000, 'input_price' => 3.0, 'output_price' => 15.0]]);
        $compactor = new ContextCompactor($llm, $models, new NullLogger());

        $memories = $compactor->extractMemories('Summary');

        $this->assertSame([], $memories);
    }
}
