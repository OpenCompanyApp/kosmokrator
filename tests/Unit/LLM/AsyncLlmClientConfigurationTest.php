<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use PHPUnit\Framework\TestCase;

final class AsyncLlmClientConfigurationTest extends TestCase
{
    public function test_get_provider(): void
    {
        $service = $this->client(provider: 'anthropic', model: 'claude-4');

        $this->assertSame('anthropic', $service->getProvider());
    }

    public function test_get_model(): void
    {
        $service = $this->client(provider: 'anthropic', model: 'claude-4-sonnet');

        $this->assertSame('claude-4-sonnet', $service->getModel());
    }

    public function test_supports_streaming_for_z_provider_uses_native_capabilities(): void
    {
        $service = $this->client(provider: 'z', model: 'GLM-5.1');

        $this->assertFalse($service->supportsStreaming());
    }

    public function test_supports_streaming_for_anthropic(): void
    {
        $service = $this->client(provider: 'anthropic', model: 'claude-4');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_supports_streaming_for_openai(): void
    {
        $service = $this->client(provider: 'openai', model: 'gpt-4');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_supports_streaming_for_arbitrary_provider(): void
    {
        $service = $this->client(provider: 'custom_provider', model: 'model');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_provider_switch_updates_temperature_support(): void
    {
        $service = $this->client(provider: 'z', model: 'glm-5.1', temperature: 0.7);
        $supportsTemperature = new \ReflectionMethod($service, 'supportsTemperature');

        $this->assertFalse($supportsTemperature->invoke($service));

        $service->setProvider('openai');

        $this->assertTrue($supportsTemperature->invoke($service));
    }

    private function client(string $provider, string $model, int|float|null $temperature = null): AsyncLlmClient
    {
        return new AsyncLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://example.test',
            model: $model,
            systemPrompt: 'prompt',
            temperature: $temperature,
            provider: $provider,
        );
    }
}
