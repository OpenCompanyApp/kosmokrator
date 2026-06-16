<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\Tool;
use PHPUnit\Framework\TestCase;

class AsyncLlmClientTest extends TestCase
{
    public function test_supports_openai_compatible_provider(): void
    {
        $this->assertTrue(AsyncLlmClient::supportsProvider('z'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('kimi'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('mimo'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('mimo-api'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('perplexity'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('codex'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('anthropic'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('gemini'));
        $this->assertTrue(AsyncLlmClient::supportsProvider('minimax'));
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

    public function test_glm_high_reasoning_uses_thinking_payload_and_plain_wire_model(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.2',
            systemPrompt: 'prompt',
            provider: 'z',
            reasoningEffort: 'high',
        );

        $payload = (new \ReflectionMethod($client, 'buildPayload'))->invoke($client, [], [], false);

        $this->assertSame('glm-5.2', $payload['model']);
        $this->assertSame([
            'type' => 'enabled',
            'reasoning_effort' => 'high',
        ], $payload['thinking']);
    }

    public function test_glm_max_reasoning_uses_max_thinking_payload(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.2',
            systemPrompt: 'prompt',
            provider: 'z',
            reasoningEffort: 'max',
        );

        $payload = (new \ReflectionMethod($client, 'buildPayload'))->invoke($client, [], [], false);

        $this->assertSame([
            'type' => 'enabled',
            'reasoning_effort' => 'max',
        ], $payload['thinking']);
    }

    public function test_glm_reasoning_defaults_to_max(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.2',
            systemPrompt: 'prompt',
            provider: 'z',
        );

        $payload = (new \ReflectionMethod($client, 'buildPayload'))->invoke($client, [], [], false);

        $this->assertSame([
            'type' => 'enabled',
            'reasoning_effort' => 'max',
        ], $payload['thinking']);
    }

    public function test_glm_off_reasoning_disables_thinking_explicitly(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.2',
            systemPrompt: 'prompt',
            provider: 'z',
            reasoningEffort: 'off',
        );

        $payload = (new \ReflectionMethod($client, 'buildPayload'))->invoke($client, [], [], false);

        $this->assertSame(['type' => 'disabled'], $payload['thinking']);
    }

    public function test_standard_z_api_keeps_plain_glm_model_identifier(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: 'glm-5.2',
            systemPrompt: 'prompt',
            provider: 'z-api',
            reasoningEffort: 'high',
        );

        $payload = (new \ReflectionMethod($client, 'buildPayload'))->invoke($client, [], [], false);

        $this->assertSame('glm-5.2', $payload['model']);
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
