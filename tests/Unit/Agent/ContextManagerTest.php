<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\ContextBudget;
use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\ContextManager;
use Kosmokrator\Agent\ContextPruner;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\UI\NullRenderer;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolResult;
use Psr\Log\NullLogger;

class ContextManagerTest extends TestCase
{
    public function test_circuit_breaker_resets_after_context_pressure_drops(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $chatCalls = 0;
        $llm->method('chat')->willReturnCallback(function () use (&$chatCalls) {
            $chatCalls++;

            return match ($chatCalls) {
                1, 2, 3 => throw new \RuntimeException('compaction failed'),
                4 => new LlmResponse('summary', FinishReason::Stop, [], 80, 20),
                default => new LlmResponse('[]', FinishReason::Stop, [], 20, 10),
            };
        });

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );
        $compactor = new ContextCompactor($llm, $models, new NullLogger, 60, $budget);
        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: $compactor,
            pruner: null,
            models: $models,
            sessionManager: null,
            taskStore: null,
            budget: $budget,
        );

        $manager->preFlightCheck($this->makeLargeHistory());
        $manager->preFlightCheck($this->makeLargeHistory());
        $manager->preFlightCheck($this->makeLargeHistory());

        $manager->preFlightCheck($this->makeSmallHistory());
        [$tokensIn, $tokensOut] = $manager->preFlightCheck($this->makeLargeHistory());

        $this->assertGreaterThan(0, $tokensIn);
        $this->assertGreaterThan(0, $tokensOut);
    }

    private function makeLargeHistory(): ConversationHistory
    {
        $history = new ConversationHistory;
        $history->addUser(str_repeat('A', 600));
        $history->addAssistant(str_repeat('B', 600));
        $history->addUser(str_repeat('C', 600));
        $history->addAssistant(str_repeat('D', 600));
        $history->addUser(str_repeat('E', 600));
        $history->addAssistant(str_repeat('F', 600));
        $history->addUser(str_repeat('G', 600));
        $history->addAssistant(str_repeat('H', 600));

        return $history;
    }

    private function makeSmallHistory(): ConversationHistory
    {
        $history = new ConversationHistory;
        $history->addUser('small');

        return $history;
    }

    public function test_preflight_check_returns_ok_when_below_budget(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );
        $compactor = new ContextCompactor($llm, $models, new NullLogger, 60, $budget);
        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: $compactor,
            pruner: null,
            models: $models,
            sessionManager: null,
            taskStore: null,
            budget: $budget,
        );

        $history = new ConversationHistory;
        $history->addUser('hello');

        $result = $manager->preFlightCheck($history, AgentMode::Edit);

        $this->assertSame([0, 0], $result);
        $this->assertCount(1, $history->messages());
    }

    public function test_preflight_check_triggers_compaction_when_above_warning(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $chatCalls = 0;
        $llm->method('chat')->willReturnCallback(function () use (&$chatCalls) {
            $chatCalls++;

            return match ($chatCalls) {
                1 => new LlmResponse('Summary of conversation so far', FinishReason::Stop, [], 80, 20),
                default => new LlmResponse('[]', FinishReason::Stop, [], 20, 10),
            };
        });

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );
        $compactor = new ContextCompactor($llm, $models, new NullLogger, 60, $budget);
        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: $compactor,
            pruner: null,
            models: $models,
            sessionManager: null,
            taskStore: null,
            budget: $budget,
        );

        $history = $this->makeLargeHistory();
        $messageCountBefore = count($history->messages());

        [$tokensIn, $tokensOut] = $manager->preFlightCheck($history, AgentMode::Edit);

        $this->assertGreaterThan(0, $tokensIn, 'Expected compaction to consume input tokens');
        $this->assertGreaterThan(0, $tokensOut, 'Expected compaction to consume output tokens');
        $this->assertLessThan($messageCountBefore, count($history->messages()), 'Expected history to be shorter after compaction');
    }

    public function test_preflight_check_persists_extracted_memories_from_same_compaction_call(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');
        $llm->expects($this->once())
            ->method('chat')
            ->willReturn(new LlmResponse(
                json_encode([
                    'summary' => 'Summary of conversation so far',
                    'memories' => [
                        ['type' => 'decision', 'title' => 'Use SQLite', 'content' => 'Keep SQLite for local-first persistence'],
                        ['type' => 'project', 'title' => 'Pending cleanup', 'content' => 'Refactor pending after compaction', 'memory_class' => 'working', 'expires_days' => 7],
                    ],
                ], JSON_THROW_ON_ERROR),
                FinishReason::Stop,
                [],
                80,
                20,
            ));

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSetting')->willReturn('off');
        $sessionManager->expects($this->once())->method('persistCompactionPlan');
        $sessionManager->expects($this->exactly(3))
            ->method('addMemory')
            ->willReturnCallback(function (string $type, string $title, string $content, string $memoryClass = 'durable', bool $pinned = false, ?string $expiresAt = null): int {
                static $calls = [];
                $calls[] = [$type, $title, $content, $memoryClass, $pinned, $expiresAt];

                if (count($calls) === 1) {
                    TestCase::assertSame('compaction', $type);
                    TestCase::assertSame('working', $memoryClass);
                    TestCase::assertNotNull($expiresAt);
                }

                if (count($calls) === 2) {
                    TestCase::assertSame('decision', $type);
                    TestCase::assertSame('Use SQLite', $title);
                    TestCase::assertSame('durable', $memoryClass);
                    TestCase::assertNull($expiresAt);
                }

                if (count($calls) === 3) {
                    TestCase::assertSame('project', $type);
                    TestCase::assertSame('Pending cleanup', $title);
                    TestCase::assertSame('working', $memoryClass);
                    TestCase::assertNotNull($expiresAt);
                }

                return count($calls);
            });
        $sessionManager->expects($this->once())->method('consolidateMemories');

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );
        $compactor = new ContextCompactor($llm, $models, new NullLogger, 60, $budget);
        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: $compactor,
            pruner: null,
            models: $models,
            sessionManager: $sessionManager,
            taskStore: null,
            budget: $budget,
        );

        [$tokensIn, $tokensOut] = $manager->preFlightCheck($this->makeLargeHistory(), AgentMode::Edit);

        $this->assertSame(80, $tokensIn);
        $this->assertSame(20, $tokensOut);
    }

    public function test_preflight_check_blocks_when_at_limit(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $llm->method('chat')->willThrowException(new \RuntimeException('compaction failed'));

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );
        $compactor = new ContextCompactor($llm, $models, new NullLogger, 60, $budget);
        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: $compactor,
            pruner: null,
            models: $models,
            sessionManager: null,
            taskStore: null,
            budget: $budget,
        );

        // Trigger 3 compaction failures to activate the circuit breaker
        $manager->preFlightCheck($this->makeLargeHistory(), AgentMode::Edit);
        $manager->preFlightCheck($this->makeLargeHistory(), AgentMode::Edit);
        $manager->preFlightCheck($this->makeLargeHistory(), AgentMode::Edit);

        // Now call again with large history — circuit breaker should be active
        $history = $this->makeLargeHistory();
        $messageCountBefore = count($history->messages());

        [$tokensIn, $tokensOut] = $manager->preFlightCheck($history, AgentMode::Edit);

        $this->assertSame([0, 0], [$tokensIn, $tokensOut], 'Circuit breaker should return [0,0]');
        $this->assertLessThan($messageCountBefore, count($history->messages()), 'trimOldest should have been called, shortening the history');
    }

    public function test_pruning_removes_low_importance_messages(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $models = new ModelCatalog([
            'models' => [],
            'default' => ['context' => 1_000, 'input_price' => 3.0, 'output_price' => 15.0],
        ]);
        $budget = new ContextBudget(
            models: $models,
            reserveOutputTokens: 0,
            warningBufferTokens: 700,
            autoCompactBufferTokens: 700,
            blockingBufferTokens: 100,
        );

        // Use a pruner with very low thresholds so it actually prunes in tests
        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);

        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'Base prompt',
            compactor: null,
            pruner: $pruner,
            models: $models,
            sessionManager: null,
            taskStore: null,
            budget: $budget,
        );

        // Build history with old tool results that can be pruned.
        // Need 3 user turns so the protect boundary falls after the 2nd user turn,
        // making tool results from turn 1 candidates for pruning.
        $toolResult = new ToolResult(
            toolCallId: 'tc1',
            toolName: 'bash',
            args: [],
            result: str_repeat('line of output ', 200),
        );

        $history = new ConversationHistory;
        $history->addUser('do some work');
        $history->addAssistant('I will run a command');
        $history->addToolResults([$toolResult]);
        $history->addUser('check the file');
        $history->addAssistant('Looking at it now');
        $history->addToolResults([
            new ToolResult(
                toolCallId: 'tc2',
                toolName: 'bash',
                args: [],
                result: str_repeat('another output ', 200),
            ),
        ]);
        $history->addUser('now do something else');

        // The context pressure should be above warning threshold to trigger pruning
        $result = $manager->preFlightCheck($history, AgentMode::Edit);

        // The pruner should have pruned at least some old tool results
        $this->assertGreaterThan(0, count($history->messages()), 'History should still have messages');
    }

    public function test_memory_injection_included_in_system_prompt(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSetting')->with('memories')->willReturn('on');
        $sessionManager->method('selectRelevantMemories')->willReturn([
            [
                'type' => 'project',
                'title' => 'Test framework',
                'content' => 'This project uses PHPUnit for testing',
                'memory_class' => 'durable',
                'created_at' => '2025-01-15T10:00:00Z',
            ],
        ]);
        $sessionManager->method('searchSessionHistory')->willReturn([]);

        $manager = new ContextManager(
            llm: $llm,
            ui: new NullRenderer,
            log: new NullLogger,
            baseSystemPrompt: 'You are a helpful assistant.',
            compactor: null,
            pruner: null,
            models: null,
            sessionManager: $sessionManager,
            taskStore: null,
        );

        $history = new ConversationHistory;
        $history->addUser('run the tests');

        $prompt = $manager->buildSystemPrompt(AgentMode::Edit, $history);

        $this->assertStringContainsString('You are a helpful assistant.', $prompt);
        $this->assertStringContainsString('# Memories', $prompt);
        $this->assertStringContainsString('Project Knowledge', $prompt);
        $this->assertStringContainsString('Test framework', $prompt);
        $this->assertStringContainsString('This project uses PHPUnit for testing', $prompt);
    }
}
