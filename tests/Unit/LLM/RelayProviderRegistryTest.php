<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\RelayProviderRegistry;
use PHPUnit\Framework\TestCase;

final class RelayProviderRegistryTest extends TestCase
{
    private Repository $emptyConfig;
    private RelayProviderRegistry $registry;

    protected function setUp(): void
    {
        $this->emptyConfig = new Repository([]);
        $this->registry = new RelayProviderRegistry($this->emptyConfig);
    }

    // ---------------------------------------------------------------
    // 1. Has well-known providers
    // ---------------------------------------------------------------

    public function test_all_contains_well_known_providers(): void
    {
        $all = $this->registry->all();

        $this->assertArrayHasKey('openai', $all);
        $this->assertArrayHasKey('anthropic', $all);
        $this->assertArrayHasKey('codex', $all);
        $this->assertArrayHasKey('deepseek', $all);
        $this->assertArrayHasKey('groq', $all);
        $this->assertArrayHasKey('gemini', $all);
        $this->assertArrayHasKey('ollama', $all);
        $this->assertArrayHasKey('openrouter', $all);
        $this->assertArrayHasKey('mistral', $all);
        $this->assertArrayHasKey('xai', $all);
        $this->assertArrayHasKey('z', $all);
    }

    public function test_allProviders_returns_provider_names(): void
    {
        $names = $this->registry->allProviders();

        $this->assertContains('openai', $names);
        $this->assertContains('anthropic', $names);
        $this->assertContains('codex', $names);
    }

    public function test_has_returns_true_for_known_providers(): void
    {
        $this->assertTrue($this->registry->has('openai'));
        $this->assertTrue($this->registry->has('anthropic'));
        $this->assertTrue($this->registry->has('codex'));
        $this->assertTrue($this->registry->has('deepseek'));
        $this->assertTrue($this->registry->has('groq'));
        $this->assertTrue($this->registry->has('gemini'));
    }

    public function test_has_returns_false_for_unknown_provider(): void
    {
        $this->assertFalse($this->registry->has('nonexistent_provider'));
        $this->assertFalse($this->registry->has(''));
    }

    // ---------------------------------------------------------------
    // 2. driver() returns correct values
    // ---------------------------------------------------------------

    public function test_driver_returns_inferred_driver_for_well_known_providers(): void
    {
        $this->assertSame('openai', $this->registry->driver('openai'));
        $this->assertSame('anthropic', $this->registry->driver('anthropic'));
        $this->assertSame('codex', $this->registry->driver('codex'));
        $this->assertSame('openai-compatible', $this->registry->driver('deepseek'));
        $this->assertSame('groq', $this->registry->driver('groq'));
        $this->assertSame('mistral', $this->registry->driver('mistral'));
        $this->assertSame('ollama', $this->registry->driver('ollama'));
        $this->assertSame('openrouter', $this->registry->driver('openrouter'));
        $this->assertSame('xai', $this->registry->driver('xai'));
        $this->assertSame('gemini', $this->registry->driver('gemini'));
        $this->assertSame('perplexity', $this->registry->driver('perplexity'));
        $this->assertSame('glm-coding', $this->registry->driver('z'));
        $this->assertSame('glm', $this->registry->driver('z-api'));
        $this->assertSame('openai-compatible', $this->registry->driver('stepfun-plan'));
    }

    public function test_driver_returns_openai_compatible_for_unknown_provider(): void
    {
        $this->assertSame('openai-compatible', $this->registry->driver('unknown'));
    }

    public function test_driver_returns_explicit_driver_from_definition(): void
    {
        // mimo has an explicit driver in the built-in config
        $this->assertSame('openai-compatible', $this->registry->driver('mimo'));
    }

    // ---------------------------------------------------------------
    // 3. authMode() returns correct values
    // ---------------------------------------------------------------

    public function test_authMode_returns_oauth_for_codex(): void
    {
        $this->assertSame('oauth', $this->registry->authMode('codex'));
    }

    public function test_authMode_returns_api_key_for_others(): void
    {
        $this->assertSame('api_key', $this->registry->authMode('openai'));
        $this->assertSame('api_key', $this->registry->authMode('anthropic'));
        $this->assertSame('api_key', $this->registry->authMode('deepseek'));
        $this->assertSame('api_key', $this->registry->authMode('groq'));
        $this->assertSame('api_key', $this->registry->authMode('ollama'));
    }

    // ---------------------------------------------------------------
    // 4. capabilities() returns 4 bool keys
    // ---------------------------------------------------------------

    public function test_capabilities_returns_four_boolean_keys(): void
    {
        $caps = $this->registry->capabilities('openai');

        $this->assertArrayHasKey('temperature', $caps);
        $this->assertArrayHasKey('top_p', $caps);
        $this->assertArrayHasKey('max_tokens', $caps);
        $this->assertArrayHasKey('streaming', $caps);
        $this->assertCount(4, $caps);
    }

    public function test_capabilities_defaults_all_true(): void
    {
        // Built-in providers with no capabilities defined should default to all true
        $caps = $this->registry->capabilities('openai');

        $this->assertTrue($caps['temperature']);
        $this->assertTrue($caps['top_p']);
        $this->assertTrue($caps['max_tokens']);
        $this->assertTrue($caps['streaming']);
    }

    public function test_capabilities_for_unknown_provider_returns_defaults(): void
    {
        $caps = $this->registry->capabilities('nonexistent');

        $this->assertTrue($caps['temperature']);
        $this->assertTrue($caps['top_p']);
        $this->assertTrue($caps['max_tokens']);
        $this->assertTrue($caps['streaming']);
    }

    // ---------------------------------------------------------------
    // 5. supportsAsync() for various providers
    // ---------------------------------------------------------------

    public function test_supportsAsync_returns_true_for_openai_compatible_drivers(): void
    {
        $this->assertTrue($this->registry->supportsAsync('openai'));
        $this->assertTrue($this->registry->supportsAsync('deepseek'));
        $this->assertTrue($this->registry->supportsAsync('groq'));
        $this->assertTrue($this->registry->supportsAsync('mistral'));
        $this->assertTrue($this->registry->supportsAsync('ollama'));
        $this->assertTrue($this->registry->supportsAsync('openrouter'));
        $this->assertTrue($this->registry->supportsAsync('xai'));
        $this->assertTrue($this->registry->supportsAsync('z'));
        $this->assertTrue($this->registry->supportsAsync('perplexity'));
    }

    public function test_supportsAsync_returns_false_for_non_async_drivers(): void
    {
        $this->assertFalse($this->registry->supportsAsync('anthropic'));
        $this->assertFalse($this->registry->supportsAsync('codex'));
        $this->assertFalse($this->registry->supportsAsync('gemini'));
    }

    // ---------------------------------------------------------------
    // 6. providerModalities() structure
    // ---------------------------------------------------------------

    public function test_providerModalities_returns_input_output_structure(): void
    {
        $modalities = $this->registry->providerModalities('openai');

        $this->assertArrayHasKey('input', $modalities);
        $this->assertArrayHasKey('output', $modalities);
        $this->assertCount(2, $modalities);
    }

    public function test_providerModalities_defaults_to_text(): void
    {
        // Providers without explicit modalities default to text
        $modalities = $this->registry->providerModalities('openai');

        $this->assertSame(['text'], $modalities['input']);
        $this->assertSame(['text'], $modalities['output']);
    }

    public function test_providerModalities_with_explicit_modalities(): void
    {
        // mimo has explicit modalities in built-in config
        $modalities = $this->registry->providerModalities('mimo');

        $this->assertContains('text', $modalities['input']);
        $this->assertContains('image', $modalities['input']);
        $this->assertSame(['text'], $modalities['output']);
    }

    public function test_modelModalities_falls_back_to_provider_modalities(): void
    {
        $modalities = $this->registry->modelModalities('openai', 'gpt-4o');

        $this->assertArrayHasKey('input', $modalities);
        $this->assertArrayHasKey('output', $modalities);
        $this->assertSame(['text'], $modalities['input']);
        $this->assertSame(['text'], $modalities['output']);
    }

    public function test_modelModalities_for_unknown_model_returns_provider_defaults(): void
    {
        $modalities = $this->registry->modelModalities('openai', 'nonexistent-model');

        $this->assertSame(['text'], $modalities['input']);
        $this->assertSame(['text'], $modalities['output']);
    }

    // ---------------------------------------------------------------
    // 7. source() is 'built_in' for built-in providers
    // ---------------------------------------------------------------

    public function test_source_returns_built_in_for_known_providers(): void
    {
        $this->assertSame('built_in', $this->registry->source('openai'));
        $this->assertSame('built_in', $this->registry->source('anthropic'));
        $this->assertSame('built_in', $this->registry->source('codex'));
        $this->assertSame('built_in', $this->registry->source('deepseek'));
        $this->assertSame('built_in', $this->registry->source('gemini'));
    }

    // ---------------------------------------------------------------
    // 8. has() / provider() for unknown
    // ---------------------------------------------------------------

    public function test_provider_returns_null_for_unknown(): void
    {
        $this->assertNull($this->registry->provider('nonexistent_provider'));
    }

    public function test_provider_returns_array_for_known(): void
    {
        $def = $this->registry->provider('openai');

        $this->assertIsArray($def);
        $this->assertArrayHasKey('models', $def);
        $this->assertArrayHasKey('url', $def);
    }

    // ---------------------------------------------------------------
    // 9. Custom providers override built-in
    // ---------------------------------------------------------------

    public function test_custom_provider_source_is_custom(): void
    {
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'openai' => [
                        'url' => 'https://custom-openai.example.com/v1',
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);

        $this->assertSame('custom', $registry->source('openai'));
    }

    public function test_custom_provider_merges_over_builtin(): void
    {
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'openai' => [
                        'url' => 'https://custom-openai.example.com/v1',
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);
        $provider = $registry->provider('openai');

        // The custom URL overrides the built-in one
        $this->assertSame('https://custom-openai.example.com/v1', $provider['url']);
        // But the built-in models should still be present (deep merge)
        $this->assertArrayHasKey('models', $provider);
        $this->assertArrayHasKey('gpt-4o', $provider['models']);
    }

    public function test_entirely_new_custom_provider(): void
    {
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'my-custom' => [
                        'driver' => 'openai-compatible',
                        'url' => 'https://my-custom.example.com/v1',
                        'auth' => 'api_key',
                        'models' => [
                            'my-model' => [
                                'display_name' => 'My Model',
                                'context' => 32000,
                                'max_output' => 4096,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);

        $this->assertTrue($registry->has('my-custom'));
        $this->assertSame('custom', $registry->source('my-custom'));
        $this->assertSame('openai-compatible', $registry->driver('my-custom'));
        $this->assertSame('api_key', $registry->authMode('my-custom'));
        $this->assertSame('https://my-custom.example.com/v1', $registry->provider('my-custom')['url']);
    }

    public function test_builtin_providers_remain_when_custom_added(): void
    {
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'my-custom' => [
                        'driver' => 'openai-compatible',
                        'url' => 'https://custom.example.com',
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);

        // Built-in providers still exist
        $this->assertTrue($registry->has('openai'));
        $this->assertTrue($registry->has('anthropic'));
        $this->assertSame('built_in', $registry->source('openai'));

        // Custom provider also exists
        $this->assertTrue($registry->has('my-custom'));
        $this->assertSame('custom', $registry->source('my-custom'));
    }

    // ---------------------------------------------------------------
    // url() method
    // ---------------------------------------------------------------

    public function test_url_returns_builtin_url_by_default(): void
    {
        $this->assertSame('https://api.openai.com/v1', $this->registry->url('openai'));
        $this->assertSame('https://api.anthropic.com/v1', $this->registry->url('anthropic'));
    }

    public function test_url_prefers_prism_config_over_builtin(): void
    {
        $config = new Repository([
            'prism' => [
                'providers' => [
                    'openai' => [
                        'url' => 'https://custom.openai.example.com/v1',
                    ],
                ],
            ],
        ]);

        $registry = new RelayProviderRegistry($config);

        $this->assertSame('https://custom.openai.example.com/v1', $registry->url('openai'));
    }

    public function test_url_returns_empty_string_for_unknown_provider_with_no_config(): void
    {
        $this->assertSame('', $this->registry->url('nonexistent'));
    }
}
