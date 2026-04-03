<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use OpenCompany\PrismRelay\Meta\ProviderMeta;

class ModelCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $models;

    private array $default;

    private ?ProviderMeta $providerMeta;

    /** @var array<string, string> */
    private array $providerAliases = [
        'z-api' => 'z',
        'kimi-coding' => 'kimi',
        'minimax-cn' => 'minimax',
    ];

    public function __construct(array $config, ?ProviderMeta $providerMeta = null)
    {
        $this->providerMeta = $providerMeta;
        $this->default = $config['default'] ?? [
            'context' => 128_000,
            'input_price' => 3.0,
            'output_price' => 15.0,
        ];
        $this->models = $this->buildModelMap($config['models'] ?? []);
    }

    public function contextWindow(string $model): int
    {
        $spec = $this->resolve($model);

        return (int) ($spec['context'] ?? $this->default['context']);
    }

    public function estimateCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float
    {
        return $this->estimateActualCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    public function estimateActualCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float
    {
        $spec = $this->resolve($model, $provider);
        if (($spec['pricing_kind'] ?? 'paid') === 'token_plan') {
            return 0.0;
        }

        $inRate = (float) ($spec['input_price'] ?? $this->default['input_price']);
        $outRate = (float) ($spec['output_price'] ?? $this->default['output_price']);
        $cachedReadRate = (float) ($spec['cached_input_price'] ?? $inRate);
        $cachedWriteRate = (float) ($spec['cached_write_price'] ?? $inRate);
        $uncachedInputTokens = max(0, $tokensIn - $cacheReadInputTokens - $cacheWriteInputTokens);

        return round(
            ($uncachedInputTokens * $inRate / 1_000_000)
            + ($cacheReadInputTokens * $cachedReadRate / 1_000_000)
            + ($cacheWriteInputTokens * $cachedWriteRate / 1_000_000)
            + ($tokensOut * $outRate / 1_000_000),
            4,
        );
    }

    public function estimateDisplayCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float
    {
        $spec = $this->resolve($model, $provider);

        if (($spec['pricing_kind'] ?? 'paid') !== 'coding_plan') {
            return $this->estimateActualCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
        }

        $referenceIn = $spec['reference_input_price'] ?? null;
        $referenceOut = $spec['reference_output_price'] ?? null;
        if (! is_numeric($referenceIn) || ! is_numeric($referenceOut)) {
            return $this->estimateActualCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
        }

        $cachedReadRate = (float) ($spec['cached_input_price'] ?? $referenceIn);
        $cachedWriteRate = (float) ($spec['cached_write_price'] ?? $referenceIn);
        $uncachedInputTokens = max(0, $tokensIn - $cacheReadInputTokens - $cacheWriteInputTokens);

        return round(
            ($uncachedInputTokens * (float) $referenceIn / 1_000_000)
            + ($cacheReadInputTokens * $cachedReadRate / 1_000_000)
            + ($cacheWriteInputTokens * $cachedWriteRate / 1_000_000)
            + ($tokensOut * (float) $referenceOut / 1_000_000),
            4,
        );
    }

    public function estimateCacheSavings(
        string $model,
        int $tokensIn,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float {
        if ($cacheReadInputTokens === 0 && $cacheWriteInputTokens === 0) {
            return 0.0;
        }

        $spec = $this->resolve($model, $provider);
        if (($spec['pricing_kind'] ?? 'paid') === 'token_plan') {
            return 0.0;
        }

        $inRate = (float) ($spec['input_price'] ?? $this->default['input_price']);
        $cachedReadRate = (float) ($spec['cached_input_price'] ?? $inRate);
        $cachedWriteRate = (float) ($spec['cached_write_price'] ?? $inRate);
        $baseline = $tokensIn * $inRate / 1_000_000;
        $actual = max(0, $tokensIn - $cacheReadInputTokens - $cacheWriteInputTokens) * $inRate / 1_000_000
            + $cacheReadInputTokens * $cachedReadRate / 1_000_000
            + $cacheWriteInputTokens * $cachedWriteRate / 1_000_000;

        return round(max(0.0, $baseline - $actual), 4);
    }

    public function supportsThinking(string $model): bool
    {
        return (bool) ($this->resolve($model)['thinking'] ?? false);
    }

    public function supportsStreaming(string $model): bool
    {
        return (bool) ($this->resolve($model)['streaming'] ?? true);
    }

    /**
     * @return list<string>
     */
    public function modelsForProvider(string $provider): array
    {
        $provider = $this->canonicalProvider($provider);

        if ($this->providerMeta !== null && $this->providerMeta->has($provider)) {
            return $this->providerMeta->models($provider);
        }

        $models = [];

        foreach ($this->models as $name => $spec) {
            if (($spec['provider'] ?? null) === $provider) {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * @return array<string, list<string>>
     */
    public function modelsByProvider(): array
    {
        $providers = [];

        foreach ($this->models as $name => $spec) {
            $canonical = (string) ($spec['provider'] ?? '');
            if ($canonical === '') {
                continue;
            }

            foreach ($this->providersForCanonical($canonical) as $provider) {
                $providers[$provider] ??= [];
                $providers[$provider][] = $name;
            }
        }

        foreach ($providers as &$models) {
            $models = array_values(array_unique($models));
        }

        return $providers;
    }

    /**
     * Resolve model spec — tries exact match first, then substring match.
     */
    private function resolve(string $model, ?string $provider = null): array
    {
        $key = strtolower($model);
        $providerKey = $provider !== null && $provider !== '' ? strtolower($provider.'/'.$model) : null;

        if ($providerKey !== null && isset($this->models[$providerKey])) {
            return $this->models[$providerKey];
        }

        // Exact match
        if (isset($this->models[$key])) {
            return $this->models[$key];
        }

        // Substring match (e.g. "z/GLM-5.1" matches "glm-5.1")
        // Use longest match first to avoid "glm" matching before "glm-5.1"
        $bestMatch = null;
        $bestLength = 0;

        foreach ($this->models as $name => $spec) {
            $lowerName = strtolower($name);
            if (str_contains($key, $lowerName) && strlen($lowerName) > $bestLength) {
                $bestMatch = $spec;
                $bestLength = strlen($lowerName);
            }
        }

        if ($bestMatch !== null) {
            return $bestMatch;
        }

        return $this->default;
    }

    private function canonicalProvider(string $provider): string
    {
        if ($this->providerMeta !== null && $this->providerMeta->has($provider)) {
            return $provider;
        }

        if ($this->providerMeta !== null) {
            $canonical = $this->providerMeta->registry()->canonicalProvider($provider);
            if ($canonical !== null) {
                return $canonical;
            }
        }

        return $this->providerAliases[$provider] ?? $provider;
    }

    /**
     * @return list<string>
     */
    private function providersForCanonical(string $provider): array
    {
        if ($this->providerMeta !== null && $this->providerMeta->has($provider)) {
            return [$provider];
        }

        if ($this->providerMeta !== null) {
            $aliases = [];
            foreach ($this->providerMeta->registry()->registrationNames() as $candidate) {
                $canonical = $this->providerMeta->registry()->canonicalProvider($candidate);
                if ($canonical === $provider) {
                    $aliases[] = $candidate;
                }
            }

            if ($aliases !== []) {
                return array_values(array_unique($aliases));
            }
        }

        $providers = [$provider];

        foreach ($this->providerAliases as $alias => $canonical) {
            if ($canonical === $provider) {
                $providers[] = $alias;
            }
        }

        return $providers;
    }

    /**
     * @param  array<string, array<string, mixed>>  $localModels
     * @return array<string, array<string, mixed>>
     */
    private function buildModelMap(array $localModels): array
    {
        $models = [];

        if ($this->providerMeta !== null) {
            foreach ($this->providerMeta->allProviders() as $provider) {
                foreach ($this->providerMeta->models($provider) as $model) {
                    $info = $this->providerMeta->modelInfo($provider, $model);
                    $spec = [
                        'provider' => $provider,
                        'context' => $info->contextWindow,
                        'max_output' => $info->maxOutput,
                        'input_price' => $info->inputPricePerMillion ?? $this->default['input_price'],
                        'output_price' => $info->outputPricePerMillion ?? $this->default['output_price'],
                        'cached_input_price' => $info->cachedInputPricePerMillion,
                        'cached_write_price' => $info->cachedWritePricePerMillion,
                        'pricing_kind' => $info->pricingKind,
                        'reference_input_price' => $info->referenceInputPricePerMillion,
                        'reference_output_price' => $info->referenceOutputPricePerMillion,
                        'status' => $info->status,
                        'thinking' => $info->thinking,
                        'streaming' => true,
                    ];

                    $models[strtolower($provider.'/'.$model)] = $spec;
                    $models[strtolower($model)] ??= $spec;
                }
            }
        }

        foreach ($localModels as $name => $spec) {
            $key = strtolower($name);

            if (! isset($models[$key])) {
                $models[$key] = $spec;

                continue;
            }

            foreach (['streaming', 'tool_streaming'] as $overrideKey) {
                if (array_key_exists($overrideKey, $spec)) {
                    $models[$key][$overrideKey] = $spec[$overrideKey];
                }
            }
        }

        return $models;
    }
}
