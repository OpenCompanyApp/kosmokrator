<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use PHPUnit\Framework\TestCase;

class AsyncLlmClientTest extends TestCase
{
    public function test_supports_openai_compatible_provider(): void
    {
        $this->assertTrue(AsyncLlmClient::supportsProvider('z'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('kimi'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('perplexity'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('codex'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('anthropic'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('gemini'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('minimax'));
    }
}
