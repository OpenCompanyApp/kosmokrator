<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ContextBudget;
use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\ContextManager;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\UI\NullRenderer;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
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
}
