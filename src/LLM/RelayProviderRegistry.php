<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Illuminate\Config\Repository;

/**
 * Merged registry of built-in and custom LLM provider definitions.
 *
 * Builds provider specs from repo-owned config and merges user-defined custom providers on top.
 * Source of truth for repo-owned provider wiring.
 */
final class RelayProviderRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $providers;

    /** @var array<string, string> Maps provider ID to "built_in" or "custom" source */
    private array $sources = [];

    private readonly Repository $config;

    /**
     * @param  Repository|array<string, mixed>|null  $config  Config repository or provider map for tests/custom callers
     */
    public function __construct(
        Repository|array|null $config = null,
    ) {
        $this->config = $config instanceof Repository
            ? $config
            : new Repository($this->normalizeConfigArray($config ?? []));

        $builtIn = $this->builtInProviders();
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
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfigArray(array $config): array
    {
        if (isset($config['prism']) || isset($config['models']) || isset($config['relay'])) {
            return $config;
        }

        return ['relay' => ['providers' => $config]];
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
        return $this->canonicalProvider($provider) !== null;
    }

    public function hasProvider(string $provider): bool
    {
        return $this->has($provider);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function provider(string $provider): ?array
    {
        $canonical = $this->canonicalProvider($provider);

        return $canonical !== null ? ($this->providers[$canonical] ?? null) : null;
    }

    public function source(string $provider): string
    {
        return $this->sources[$provider] ?? 'built_in';
    }

    public function driver(string $provider): string
    {
        $definition = $this->provider($provider) ?? [];

        return (string) ($definition['driver'] ?? $this->inferDriver($provider));
    }

    public function url(string $provider): string
    {
        $configured = (string) $this->config->get("prism.providers.{$provider}.url", '');
        if ($configured !== '') {
            return $configured;
        }

        return (string) (($this->provider($provider)['url'] ?? ''));
    }

    public function authMode(string $provider): string
    {
        return (string) (($this->provider($provider)['auth'] ?? ($provider === 'codex' ? 'oauth' : 'api_key')));
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(string $provider): array
    {
        $capabilities = $this->provider($provider)['capabilities'] ?? [];

        return [
            'temperature' => (bool) ($capabilities['temperature'] ?? true),
            'top_p' => (bool) ($capabilities['top_p'] ?? true),
            'max_tokens' => (bool) ($capabilities['max_tokens'] ?? true),
            'streaming' => (bool) ($capabilities['streaming'] ?? true),
            'stream_usage' => (bool) ($capabilities['stream_usage'] ?? ($provider !== 'ollama')),
        ];
    }

    /**
     * @return array{input: list<string>, output: list<string>}
     */
    public function providerModalities(string $provider): array
    {
        $modalities = $this->provider($provider)['modalities'] ?? [];

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
        $models = $this->provider($provider)['models'] ?? [];
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

    public function canonicalProvider(string $provider): ?string
    {
        $provider = strtolower(trim($provider));
        if (isset($this->providers[$provider])) {
            return $provider;
        }

        return match ($provider) {
            'zhipuai', 'glm', 'glm-coding' => isset($this->providers['z-api']) ? 'z-api' : null,
            'kimi-for-coding' => isset($this->providers['kimi-coding']) ? 'kimi-coding' : null,
            default => null,
        };
    }

    /** @return list<string> */
    public function registrationNames(): array
    {
        return $this->allProviders();
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
     * @return array<string, array<string, mixed>>
     */
    private function builtInProviders(): array
    {
        $configuredProviders = $this->config->get('prism.providers', []);
        $models = $this->config->get('models.models', []);
        $providers = [];

        foreach ($configuredProviders as $provider => $definition) {
            if (! is_string($provider) || ! is_array($definition)) {
                continue;
            }

            $providers[$provider] = [
                'label' => $this->humanize($provider),
                'description' => $this->humanize($provider).' provider',
                'driver' => $this->inferDriver($provider),
                'auth' => $provider === 'ollama' ? 'none' : ($provider === 'codex' ? 'oauth' : 'api_key'),
                'url' => (string) ($definition['url'] ?? ''),
                'capabilities' => array_merge([
                    'temperature' => ! in_array($provider, ['codex'], true),
                    'top_p' => true,
                    'max_tokens' => true,
                    'streaming' => true,
                    'stream_usage' => $provider !== 'ollama',
                ], ProviderCapabilities::defaults()[$provider] ?? []),
                'modalities' => ['input' => ['text'], 'output' => ['text']],
                'models' => [],
            ];
        }

        foreach ($models as $key => $spec) {
            if (! is_string($key) || ! is_array($spec)) {
                continue;
            }

            $provider = (string) ($spec['provider'] ?? '');
            if ($provider === '') {
                continue;
            }

            foreach ($this->providerAliasesForModelProvider($provider) as $providerId) {
                $providers[$providerId] ??= [
                    'label' => $this->humanize($providerId),
                    'description' => $this->humanize($providerId).' provider',
                    'driver' => $this->inferDriver($providerId),
                    'auth' => $providerId === 'codex' ? 'oauth' : 'api_key',
                    'url' => '',
                    'capabilities' => ['temperature' => true, 'top_p' => true, 'max_tokens' => true, 'streaming' => true, 'stream_usage' => true],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                    'models' => [],
                ];

                $modelId = (string) ($spec['id'] ?? $this->modelIdFromConfigKey($key));
                $providers[$providerId]['models'][$modelId] = [
                    'display_name' => isset($spec['display_name']) ? (string) $spec['display_name'] : $this->humanize($modelId),
                    'context' => (int) ($spec['context'] ?? 0),
                    'max_output' => (int) ($spec['max_output'] ?? 0),
                    'input' => isset($spec['input_price']) ? (float) $spec['input_price'] : null,
                    'output' => isset($spec['output_price']) ? (float) $spec['output_price'] : null,
                    'cached_input' => isset($spec['cached_input_price']) ? (float) $spec['cached_input_price'] : null,
                    'cached_write' => isset($spec['cached_write_price']) ? (float) $spec['cached_write_price'] : null,
                    'thinking' => (bool) ($spec['thinking'] ?? false),
                    'pricing_kind' => (string) ($spec['pricing_kind'] ?? 'paid'),
                    'reference_input_price' => isset($spec['reference_input_price']) ? (float) $spec['reference_input_price'] : null,
                    'reference_output_price' => isset($spec['reference_output_price']) ? (float) $spec['reference_output_price'] : null,
                    'status' => isset($spec['status']) ? (string) $spec['status'] : null,
                ];
            }
        }

        foreach ($providers as $provider => $definition) {
            if ($definition['models'] !== []) {
                $providers[$provider]['default_model'] = array_key_first($definition['models']);
            }
        }

        return $providers;
    }

    /** @return list<string> */
    private function providerAliasesForModelProvider(string $provider): array
    {
        return match ($provider) {
            'z' => ['z', 'z-api'],
            'kimi' => ['kimi', 'kimi-coding'],
            'minimax' => ['minimax', 'minimax-cn'],
            default => [$provider],
        };
    }

    private function modelIdFromConfigKey(string $key): string
    {
        $parts = explode('/', $key);

        return (string) end($parts);
    }

    /**
     * Coerce a value into a clean list of non-empty strings, defaulting to ["text"].
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return ['text'];
        }

        return array_values(array_map('strval', array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '')));
    }

    /** Guess the native driver name from a provider identifier when no explicit driver is configured. */
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

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['-', '_', '/'], ' ', $value));
    }
}
