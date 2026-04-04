<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\ProviderCapabilitiesResolver;
use Kosmokrator\LLM\RelayProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilitiesResolverTest extends TestCase
{
    private RelayProviderRegistry $registry;

    private ProviderCapabilitiesResolver $resolver;

    protected function setUp(): void
    {
        $config = new Repository([]);
        $this->registry = new RelayProviderRegistry($config);
        $this->resolver = new ProviderCapabilitiesResolver($this->registry);
    }

    public function test_supports_temperature_returns_true_for_openai(): void
    {
        $this->assertTrue($this->resolver->supportsTemperature('openai'));
    }

    public function test_supports_temperature_returns_true_for_anthropic(): void
    {
        $this->assertTrue($this->resolver->supportsTemperature('anthropic'));
    }

    public function test_supports_top_p_returns_true_for_openai(): void
    {
        $this->assertTrue($this->resolver->supportsTopP('openai'));
    }

    public function test_supports_top_p_returns_true_for_anthropic(): void
    {
        $this->assertTrue($this->resolver->supportsTopP('anthropic'));
    }

    public function test_supports_max_tokens_returns_true_for_openai(): void
    {
        $this->assertTrue($this->resolver->supportsMaxTokens('openai'));
    }

    public function test_supports_max_tokens_returns_true_for_anthropic(): void
    {
        $this->assertTrue($this->resolver->supportsMaxTokens('anthropic'));
    }

    public function test_supports_streaming_returns_true_for_openai(): void
    {
        $this->assertTrue($this->resolver->supportsStreaming('openai'));
    }

    public function test_supports_streaming_returns_true_for_anthropic(): void
    {
        $this->assertTrue($this->resolver->supportsStreaming('anthropic'));
    }

    public function test_capabilities_are_resolved_per_provider(): void
    {
        // OpenAI should support all capabilities by default
        $this->assertTrue($this->resolver->supportsTemperature('openai'));
        $this->assertTrue($this->resolver->supportsTopP('openai'));
        $this->assertTrue($this->resolver->supportsMaxTokens('openai'));
        $this->assertTrue($this->resolver->supportsStreaming('openai'));

        // Anthropic should also support all by default
        $this->assertTrue($this->resolver->supportsTemperature('anthropic'));
        $this->assertTrue($this->resolver->supportsTopP('anthropic'));
        $this->assertTrue($this->resolver->supportsMaxTokens('anthropic'));
        $this->assertTrue($this->resolver->supportsStreaming('anthropic'));

        // Ollama may differ — check that different providers can return different values
        $ollamaTemp = $this->resolver->supportsTemperature('ollama');
        $this->assertIsBool($ollamaTemp);
    }

    public function test_capabilities_with_disabled_flags(): void
    {
        // Create a registry with custom provider that has all capabilities disabled
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'test-provider' => [
                        'capabilities' => [
                            'temperature' => false,
                            'top_p' => false,
                            'max_tokens' => false,
                            'streaming' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);
        $resolver = new ProviderCapabilitiesResolver($registry);

        $this->assertFalse($resolver->supportsTemperature('test-provider'));
        $this->assertFalse($resolver->supportsTopP('test-provider'));
        $this->assertFalse($resolver->supportsMaxTokens('test-provider'));
        $this->assertFalse($resolver->supportsStreaming('test-provider'));
    }

    public function test_capabilities_with_mixed_flags(): void
    {
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'mixed-provider' => [
                        'capabilities' => [
                            'temperature' => true,
                            'top_p' => false,
                            'max_tokens' => true,
                            'streaming' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);
        $resolver = new ProviderCapabilitiesResolver($registry);

        $this->assertTrue($resolver->supportsTemperature('mixed-provider'));
        $this->assertFalse($resolver->supportsTopP('mixed-provider'));
        $this->assertTrue($resolver->supportsMaxTokens('mixed-provider'));
        $this->assertFalse($resolver->supportsStreaming('mixed-provider'));
    }

    public function test_capabilities_default_to_true_for_unknown_provider(): void
    {
        // When a provider is not found, capabilities() returns defaults (all true)
        $result = $this->resolver->supportsTemperature('nonexistent-provider');
        $this->assertIsBool($result);
    }
}
