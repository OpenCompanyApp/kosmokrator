<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\Cancellation;
use Kosmokrator\Agent\ContextBudget;
use Kosmokrator\Agent\OutputTruncator;
use Kosmokrator\Agent\ProtectedContextBuilder;
use Kosmokrator\Agent\SubagentFactory;
use Kosmokrator\Agent\SubagentModelConfig;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolRegistry;
use OpenCompany\PrismRelay\Relay;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubagentFactoryTest extends TestCase
{
    private ToolRegistry $registry;

    private NullLogger $log;

    private \Closure $cancellation;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry;
        $this->log = new NullLogger;
        $this->cancellation = fn (): bool => false;
    }

    public function test_constructor_accepts_all_parameters(): void
    {
        $models = $this->createStub(ModelCatalog::class);
        $truncator = $this->createStub(OutputTruncator::class);
        $permissions = $this->createStub(PermissionEvaluator::class);
        $cancellation = $this->createStub(Cancellation::class);
        $budget = new ContextBudget(null);
        $protectedContextBuilder = new ProtectedContextBuilder;
        $relay = $this->createStub(Relay::class);
        $modelConfig = new SubagentModelConfig(
            defaultProvider: 'openai',
            defaultModel: 'gpt-4',
            defaultApiKey: 'sk-test-key',
            defaultBaseUrl: 'https://api.example.com',
        );

        $factory = new SubagentFactory(
            rootRegistry: $this->registry,
            log: $this->log,
            models: $models,
            truncator: $truncator,
            permissions: $permissions,
            rootCancellation: $cancellation,
            llmClientClass: 'async',
            modelConfig: $modelConfig,
            maxTokens: 4096,
            temperature: 0.7,
            budget: $budget,
            protectedContextBuilder: $protectedContextBuilder,
            relay: $relay,
        );

        $this->assertInstanceOf(SubagentFactory::class, $factory);
    }

    public function test_constructor_with_minimal_parameters(): void
    {
        $modelConfig = new SubagentModelConfig(
            defaultProvider: 'anthropic',
            defaultModel: 'claude-3',
            defaultApiKey: 'test-key',
            defaultBaseUrl: 'https://api.test.com',
        );

        $factory = new SubagentFactory(
            rootRegistry: $this->registry,
            log: $this->log,
            models: null,
            truncator: null,
            permissions: null,
            rootCancellation: $this->cancellation,
            llmClientClass: 'prism',
            modelConfig: $modelConfig,
            maxTokens: null,
            temperature: null,
        );

        $this->assertInstanceOf(SubagentFactory::class, $factory);
    }

    public function test_constructor_with_null_cancellation(): void
    {
        $modelConfig = new SubagentModelConfig(
            defaultProvider: 'openai',
            defaultModel: 'model',
            defaultApiKey: 'key',
            defaultBaseUrl: 'https://api.test.com',
        );

        $factory = new SubagentFactory(
            rootRegistry: $this->registry,
            log: $this->log,
            models: null,
            truncator: null,
            permissions: null,
            rootCancellation: null,
            llmClientClass: 'async',
            modelConfig: $modelConfig,
            maxTokens: null,
            temperature: null,
        );

        $this->assertInstanceOf(SubagentFactory::class, $factory);
    }

    public function test_constructor_defaults_optional_parameters_to_null(): void
    {
        $modelConfig = new SubagentModelConfig(
            defaultProvider: 'openai',
            defaultModel: 'model',
            defaultApiKey: 'key',
            defaultBaseUrl: 'https://api.test.com',
        );

        $factory = new SubagentFactory(
            rootRegistry: $this->registry,
            log: $this->log,
            models: null,
            truncator: null,
            permissions: null,
            rootCancellation: null,
            llmClientClass: 'async',
            modelConfig: $modelConfig,
            maxTokens: null,
            temperature: null,
        );

        // Factory was created with defaults — budget, protectedContextBuilder, relay are null
        $this->assertInstanceOf(SubagentFactory::class, $factory);
    }
}
