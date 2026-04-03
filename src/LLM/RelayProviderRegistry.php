<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Illuminate\Config\Repository;

/**
 * Merged registry of built-in and custom LLM provider definitions from PrismRelay config.
 *
 * Loads provider specs (driver, URL, auth mode, capabilities, modalities) from the relay.php
 * config file and merges any user-defined custom providers on top. Queried by
 * RelayProviderRegistrar (driver selection), ProviderCatalog (UI metadata), and
 * ProviderCapabilitiesResolver (feature flags). Source of truth for provider wiring.
 */
final class RelayProviderRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $providers;

    /** @var array<string, string> Maps provider ID to "built_in" or "custom" source */
    private array $sources = [];

    /**
     * @param Repository $config Illuminate config repository for reading relay.providers overrides
     */
    public function __construct(
        private readonly Repository $config,
    ) {
        $builtIn = require dirname(__DIR__, 2).'/vendor/opencompanyapp/prism-relay/config/relay.php';
        $custom = $this->config->get('relay.providers', []);

        $this->providers = $builtIn;
        foreach (array_keys($builtIn) as $provider) {
            $this->sources[$provider] = 'built_in';
        }

        foreach ($custom as $provider => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $this->providers[$provider] = $this->mergeProvider($this->providers[$provider] ?? [], $definition);
            $this->sources[$provider] = 'custom';
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<string>
     */
    public function allProviders(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function provider(string $provider): ?array
    {
        return $this->providers[$provider] ?? null;
    }

    public function source(string $provider): string
    {
        return $this->sources[$provider] ?? 'built_in';
    }

    public function driver(string $provider): string
    {
        $definition = $this->providers[$provider] ?? [];

        return (string) ($definition['driver'] ?? $this->inferDriver($provider));
    }

    public function url(string $provider): string
    {
        $configured = (string) $this->config->get("prism.providers.{$provider}.url", '');
        if ($configured !== '') {
            return $configured;
        }

        return (string) (($this->providers[$provider]['url'] ?? ''));
    }

    public function authMode(string $provider): string
    {
        return (string) (($this->providers[$provider]['auth'] ?? ($provider === 'codex' ? 'oauth' : 'api_key')));
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(string $provider): array
    {
        $capabilities = $this->providers[$provider]['capabilities'] ?? [];

        return [
            'temperature' => (bool) ($capabilities['temperature'] ?? true),
            'top_p' => (bool) ($capabilities['top_p'] ?? true),
            'max_tokens' => (bool) ($capabilities['max_tokens'] ?? true),
            'streaming' => (bool) ($capabilities['streaming'] ?? true),
        ];
    }

    /**
     * @return array{input: list<string>, output: list<string>}
     */
    public function providerModalities(string $provider): array
    {
        $modalities = $this->providers[$provider]['modalities'] ?? [];

        return [
            'input' => $this->stringList($modalities['input'] ?? ['text']),
            'output' => $this->stringList($modalities['output'] ?? ['text']),
        ];
    }

    /**
     * @return array{input: list<string>, output: list<string>}
     */
    public function modelModalities(string $provider, string $model): array
    {
        $models = $this->providers[$provider]['models'] ?? [];
        $modelModalities = is_array($models[$model]['modalities'] ?? null)
            ? $models[$model]['modalities']
            : [];

        $providerModalities = $this->providerModalities($provider);

        return [
            'input' => $this->stringList($modelModalities['input'] ?? $providerModalities['input']),
            'output' => $this->stringList($modelModalities['output'] ?? $providerModalities['output']),
        ];
    }

    /**
     * Whether the provider can be used with AsyncLlmClient (OpenAI-compatible drivers).
     */
    public function supportsAsync(string $provider): bool
    {
        return in_array($this->driver($provider), [
            'openai',
            'openai-compatible',
            'deepseek',
            'groq',
            'mistral',
            'ollama',
            'openrouter',
            'perplexity',
            'xai',
            'z',
            'glm',
            'glm-coding',
            'kimi',
            'kimi-coding',
        ], true);
    }

    /**
     * Deep-merge two provider definition arrays, with $override taking precedence.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function mergeProvider(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeProvider($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Coerce a value into a clean list of non-empty strings, defaulting to ["text"].
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return ['text'];
        }

        return array_values(array_map('strval', array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '')));
    }

    /** Guess the Prism driver name from a provider identifier when no explicit driver is configured. */
    private function inferDriver(string $provider): string
    {
        return match ($provider) {
            'codex' => 'codex',
            'z-api' => 'glm',
            'z' => 'glm-coding',
            'kimi' => 'kimi',
            'kimi-coding' => 'kimi-coding',
            'minimax' => 'minimax',
            'minimax-cn' => 'minimax-cn',
            'stepfun' => 'openai-compatible',
            'stepfun-plan' => 'openai-compatible',
            'openrouter' => 'openrouter',
            'openai' => 'openai',
            'anthropic' => 'anthropic',
            'gemini' => 'gemini',
            'deepseek' => 'deepseek',
            'groq' => 'groq',
            'mistral' => 'mistral',
            'xai' => 'xai',
            'ollama' => 'ollama',
            'perplexity' => 'perplexity',
            default => 'openai-compatible',
        };
    }
}
