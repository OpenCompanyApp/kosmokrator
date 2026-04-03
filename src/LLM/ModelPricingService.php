<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * Cost estimation for LLM completions — actual billing, display-friendly pricing, and cache savings.
 *
 * Operates on model specs resolved by ModelDefinitionSource. Handles pricing_kind variants
 * (paid, token_plan, coding_plan) with separate cached read/write token rates.
 */
class ModelPricingService
{
    public function __construct(
        private readonly ModelDefinitionSource $source,
    ) {}

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
        return $this->estimateActualCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $provider);
    }

    /**
     * Calculate the actual cost in USD for a completion request, respecting pricing_kind.
     *
     * Models with pricing_kind "token_plan" are treated as zero-cost.
     * All other models use their configured per-million-token rates, with separate
     * rates for cached read/write tokens when available.
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
        $spec = $this->source->resolve($model, $provider);
        $defaults = $this->source->defaults();

        if (($spec['pricing_kind'] ?? 'paid') === 'token_plan') {
            return 0.0;
        }

        $inRate = (float) ($spec['input_price'] ?? $defaults['input_price']);
        $outRate = (float) ($spec['output_price'] ?? $defaults['output_price']);
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

    /**
     * Calculate a display-friendly cost using reference pricing for coding-plan models.
     *
     * For "coding_plan" models, uses reference prices instead of actual prices so users
     * see an equivalent pay-as-you-go cost. For all other pricing kinds, delegates to estimateActualCost().
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
        $spec = $this->source->resolve($model, $provider);

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
        if ($cacheReadInputTokens === 0 && $cacheWriteInputTokens === 0) {
            return 0.0;
        }

        $spec = $this->source->resolve($model, $provider);
        $defaults = $this->source->defaults();

        if (($spec['pricing_kind'] ?? 'paid') === 'token_plan') {
            return 0.0;
        }

        $inRate = (float) ($spec['input_price'] ?? $defaults['input_price']);
        $cachedReadRate = (float) ($spec['cached_input_price'] ?? $inRate);
        $cachedWriteRate = (float) ($spec['cached_write_price'] ?? $inRate);
        $baseline = $tokensIn * $inRate / 1_000_000;
        $actual = max(0, $tokensIn - $cacheReadInputTokens - $cacheWriteInputTokens) * $inRate / 1_000_000
            + $cacheReadInputTokens * $cachedReadRate / 1_000_000
            + $cacheWriteInputTokens * $cachedWriteRate / 1_000_000;

        return round(max(0.0, $baseline - $actual), 4);
    }
}
