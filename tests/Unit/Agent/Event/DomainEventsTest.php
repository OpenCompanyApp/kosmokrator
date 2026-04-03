<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent\Event;

use Kosmokrator\Agent\Event\ContextCompacted;
use Kosmokrator\Agent\Event\LlmResponseReceived;
use Kosmokrator\Agent\Event\MessagePersisted;
use PHPUnit\Framework\TestCase;

class DomainEventsTest extends TestCase
{
    public function test_llm_response_received_stores_all_fields(): void
    {
        $event = new LlmResponseReceived(
            promptTokens: 1000,
            completionTokens: 500,
            cacheReadTokens: 200,
            cacheWriteTokens: 100,
            model: 'anthropic/claude-4',
        );

        $this->assertSame(1000, $event->promptTokens);
        $this->assertSame(500, $event->completionTokens);
        $this->assertSame(200, $event->cacheReadTokens);
        $this->assertSame(100, $event->cacheWriteTokens);
        $this->assertSame('anthropic/claude-4', $event->model);
    }

    public function test_llm_response_received_with_zero_cache_tokens(): void
    {
        $event = new LlmResponseReceived(
            promptTokens: 500,
            completionTokens: 250,
            cacheReadTokens: 0,
            cacheWriteTokens: 0,
            model: 'openai/gpt-4',
        );

        $this->assertSame(0, $event->cacheReadTokens);
        $this->assertSame(0, $event->cacheWriteTokens);
    }

    public function test_message_persisted_stores_all_fields(): void
    {
        $event = new MessagePersisted(
            role: 'assistant',
            tokensIn: 1000,
            tokensOut: 500,
        );

        $this->assertSame('assistant', $event->role);
        $this->assertSame(1000, $event->tokensIn);
        $this->assertSame(500, $event->tokensOut);
    }

    public function test_message_persisted_with_zero_tokens(): void
    {
        $event = new MessagePersisted(
            role: 'user',
            tokensIn: 0,
            tokensOut: 0,
        );

        $this->assertSame('user', $event->role);
        $this->assertSame(0, $event->tokensIn);
        $this->assertSame(0, $event->tokensOut);
    }

    public function test_context_compacted_stores_all_fields(): void
    {
        $event = new ContextCompacted(
            tokensSaved: 5000,
            compactionTokensIn: 800,
            compactionTokensOut: 200,
        );

        $this->assertSame(5000, $event->tokensSaved);
        $this->assertSame(800, $event->compactionTokensIn);
        $this->assertSame(200, $event->compactionTokensOut);
    }

    public function test_context_compacted_with_zero_saved(): void
    {
        $event = new ContextCompacted(
            tokensSaved: 0,
            compactionTokensIn: 100,
            compactionTokensOut: 50,
        );

        $this->assertSame(0, $event->tokensSaved);
        $this->assertSame(100, $event->compactionTokensIn);
    }
}
