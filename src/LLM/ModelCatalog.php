<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use OpenCompany\PrismRelay\Meta\ProviderMeta;

/**
 * Central registry of model specifications — context windows, pricing, and capabilities.
 *
 * Delegates definition-building to ModelDefinitionSource and cost estimation to ModelPricingService.
 * Provides query methods for context windows, provider groupings, and feature flags.
 */
class ModelCatalog
{
    private readonly ModelDefinitionSource $source;

    private readonly ModelPricingService $pricing;

    /**
     * @param  array  $config  Model catalog config section (models list + defaults)
     * @param  ProviderMeta  $providerMeta  Optional relay metadata for built-in model discovery
     */
    public function __construct(array $config, ?ProviderMeta $providerMeta = null)
    {
        $this->source = new ModelDefinitionSource($config, $providerMeta);
        $this->pricing = new ModelPricingService($this->source);
    }

    /**
     * @param  string  $model  Model identifier (e.g. "claude-sonnet-4-20250514")
     * @return int Context window size in tokens
     */
    public function contextWindow(string $model): int
    {
        $spec = $this->source->resolve($model);

        return (int) ($spec['context'] ?? $this->source->defaults()['context']);
    }

    /**
     * Calculate the actual cost in USD for a completion request.
     * Alias for estimateActualCost() for backward compatibility.
     *
     * @param  string  $model  Model identifier
     * @param  int  $tokensIn  Total input tokens billed
     * @param  int  $tokensOut  Total output tokens billed
     * @param  int  $cacheReadInputTokens  Tokens served from cache
     * @param  int  $cacheWriteInputTokens  Tokens written to cache
     * @param  string|null  $provider  Optional provider override for provider-specific pricing
     * @return float Cost in USD
     */
    public function estimateCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float {
        return $this->pricing->estimateCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    /**
     * Calculate the actual cost in USD for a completion request, respecting pricing_kind.
     *
     * @param  string  $model  Model identifier
     * @param  int  $tokensIn  Total input tokens billed
     * @param  int  $tokensOut  Total output tokens billed
     * @param  int  $cacheReadInputTokens  Tokens served from cache
     * @param  int  $cacheWriteInputTokens  Tokens written to cache
     * @param  string|null  $provider  Optional provider for provider-specific model lookup
     * @return float Cost in USD
     */
    public function estimateActualCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float {
        return $this->pricing->estimateActualCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    /**
     * Calculate a display-friendly cost using reference pricing for coding-plan models.
     *
     * @param  string  $model  Model identifier
     * @param  int  $tokensIn  Total input tokens
     * @param  int  $tokensOut  Total output tokens
     * @param  int  $cacheReadInputTokens  Tokens served from cache
     * @param  int  $cacheWriteInputTokens  Tokens written to cache
     * @param  string|null  $provider  Optional provider for provider-specific model lookup
     * @return float Cost in USD
     */
    public function estimateDisplayCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float {
        return $this->pricing->estimateDisplayCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    /**
     * Calculate the dollar savings from prompt caching compared to full-price input tokens.
     *
     * @param  string  $model  Model identifier
     * @param  int  $tokensIn  Total input tokens (including cached)
     * @param  int  $cacheReadInputTokens  Tokens served from cache
     * @param  int  $cacheWriteInputTokens  Tokens written to cache
     * @param  string|null  $provider  Optional provider for provider-specific model lookup
     * @return float Savings in USD (non-negative)
     */
    public function estimateCacheSavings(
        string $model,
        int $tokensIn,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        ?string $provider = null,
    ): float {
        return $this->pricing->estimateCacheSavings($model, $tokensIn, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    /** @param string $model Model identifier */
    public function supportsThinking(string $model): bool
    {
        return (bool) ($this->source->resolve($model)['thinking'] ?? false);
    }

    /** @param string $model Model identifier */
    public function supportsStreaming(string $model): bool
    {
        return (bool) ($this->source->resolve($model)['streaming'] ?? true);
    }

    /**
     * List all model identifiers available under a given provider.
     *
     * @param  string  $provider  Provider identifier
     * @return list<string>
     */
    public function modelsForProvider(string $provider): array
    {
        $provider = $this->source->canonicalProvider($provider);
        $providerMeta = $this->source->providerMeta();

        if ($providerMeta !== null && $providerMeta->has($provider)) {
            return $providerMeta->models($provider);
        }

        $models = [];

        foreach ($this->source->definitions() as $name => $spec) {
            if (($spec['provider'] ?? null) === $provider) {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * Group all models by their provider identifier (including aliases).
     *
     * @return array<string, list<string>>
     */
    public function modelsByProvider(): array
    {
        $providers = [];

        foreach ($this->source->definitions() as $name => $spec) {
            $canonical = (string) ($spec['provider'] ?? '');
            if ($canonical === '') {
                continue;
            }

            foreach ($this->source->providersForCanonical($canonical) as $provider) {
                $providers[$provider] ??= [];
                $providers[$provider][] = $name;
            }
        }

        foreach ($providers as &$models) {
            $models = array_values(array_unique($models));
        }

        return $providers;
    }
}
