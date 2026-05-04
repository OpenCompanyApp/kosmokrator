<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Tool;

class AsyncLlmClientTest extends TestCase
{
    public function test_supports_openai_compatible_provider(): void
    {
        $this->assertTrue(AsyncLlmClient::supportsProvider('z'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('kimi'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('mimo'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('mimo-api'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('perplexity'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('codex'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('anthropic'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('gemini'));
        $this->assertFalse(AsyncLlmClient::supportsProvider('minimax'));
    }

    public function test_provider_switch_updates_temperature_support(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.1',
            systemPrompt: 'prompt',
            temperature: 0.7,
            provider: 'z',
        );
        $supportsTemperature = new \ReflectionMethod($client, 'supportsTemperature');

        $this->assertFalse($supportsTemperature->invoke($client));

        $client->setProvider('openai');

        $this->assertTrue($supportsTemperature->invoke($client));
    }

    public function test_build_payload_uses_cache_planned_tool_schema(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'anthropic/claude-sonnet-4',
            systemPrompt: 'prompt',
            provider: 'openrouter',
        );
        $tool = (new Tool)->as('file_read')->for('Read files')->using(fn () => '');

        $buildPayload = new \ReflectionMethod($client, 'buildPayload');
        $payload = $buildPayload->invoke($client, [], [$tool], false);

        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertSame(['type' => 'ephemeral'], $payload['tools'][0]['cache_control']);
    }

    public function test_error_message_uses_provider_json_message_when_available(): void
    {
        $client = $this->makeClient();
        $extractErrorMessage = new \ReflectionMethod($client, 'extractErrorMessage');

        $message = $extractErrorMessage->invoke($client, '{"error":{"message":"model overloaded"}}');

        $this->assertSame('model overloaded', $message);
    }

    public function test_error_message_fallback_strips_html_and_redacts_tokens(): void
    {
        $client = $this->makeClient();
        $extractErrorMessage = new \ReflectionMethod($client, 'extractErrorMessage');

        $message = $extractErrorMessage->invoke(
            $client,
            '<html><body><h1>500</h1><pre>Authorization: Bearer sk-secret1234567890</pre></body></html>',
        );

        $this->assertStringNotContainsString('<html>', $message);
        $this->assertStringNotContainsString('sk-secret1234567890', $message);
        $this->assertStringContainsString('Bearer [REDACTED]', $message);
    }

    public function test_error_message_fallback_is_truncated(): void
    {
        $client = $this->makeClient();
        $extractErrorMessage = new \ReflectionMethod($client, 'extractErrorMessage');

        $message = $extractErrorMessage->invoke($client, str_repeat('x', 250));

        $this->assertSame(203, mb_strlen($message));
        $this->assertStringEndsWith('...', $message);
    }

    private function makeClient(): AsyncLlmClient
    {
        return new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'test-model',
            systemPrompt: 'prompt',
            provider: 'openai',
        );
    }
}
