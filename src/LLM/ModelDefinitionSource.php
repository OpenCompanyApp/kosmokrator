<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use OpenCompany\PrismRelay\Meta\ProviderMeta;

/**
 * Builds the merged model definition map from ProviderMeta (built-in) and local config overrides.
 *
 * Produces a flat lookup array keyed by lowercased model identifiers (and provider/model composites)
 * that ModelCatalog and ModelPricingService consume for queries and cost estimation.
 */
class ModelDefinitionSource
{
    /** @var array<string, array<string, mixed>> */
    private array $models;

    private array $default;

    private ?ProviderMeta $providerMeta;

    /** @var array<string, string> Maps provider aliases to their canonical name (e.g. "z-api" => "z") */
    private array $providerAliases = [
        'z-api' => 'z',
        'kimi-coding' => 'kimi',
        'minimax-cn' => 'minimax',
    ];

    /**
     * @param  array  $config  Model catalog config section (models list + defaults)
     * @param  ProviderMeta  $providerMeta  Optional relay metadata for built-in model discovery
     */
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

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->models;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->default;
    }

    public function providerMeta(): ?ProviderMeta
    {
        return $this->providerMeta;
    }

    /**
     * Resolve model spec -- tries exact match first, then substring match.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $model, ?string $provider = null): array
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

        // Suffix match: the stored key must be a suffix of the input model key.
        // Reject matches where the stored key is too short (less than 4 chars).
        // Use longest match first, then alphabetical for deterministic tiebreaking.
        $bestMatch = null;
        $bestLength = 0;
        $bestName = '';

        foreach ($this->models as $name => $spec) {
            $lowerName = strtolower($name);
            $nameLen = strlen($lowerName);

            if ($nameLen < 4) {
                continue;
            }

            // Check if the stored key is a suffix of the input key
            if (! str_ends_with($key, $lowerName)) {
                continue;
            }

            if ($nameLen > $bestLength || ($nameLen === $bestLength && $lowerName < $bestName)) {
                $bestMatch = $spec;
                $bestLength = $nameLen;
                $bestName = $lowerName;
            }
        }

        if ($bestMatch !== null) {
            return $bestMatch;
        }

        return $this->default;
    }

    /** Resolve a provider alias to its canonical name via ProviderMeta or local alias map. */
    public function canonicalProvider(string $provider): string
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
     * Expand a canonical provider name to all its registration names (including aliases).
     *
     * @return list<string>
     */
    public function providersForCanonical(string $provider): array
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
     * Merge built-in ProviderMeta models with local config overrides into a flat lookup map.
     *
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

            // Local config can only override streaming flags on built-in models
            foreach (['streaming', 'tool_streaming'] as $overrideKey) {
                if (array_key_exists($overrideKey, $spec)) {
                    $models[$key][$overrideKey] = $spec[$overrideKey];
                }
            }
        }

        return $models;
    }
}
