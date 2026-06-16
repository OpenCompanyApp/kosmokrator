<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final class ProviderMeta
{
    private readonly RelayProviderRegistry $registry;

    /** @param RelayProviderRegistry|array<string, mixed> $registry */
    public function __construct(
        RelayProviderRegistry|array $registry,
    ) {
        $this->registry = $registry instanceof RelayProviderRegistry
            ? $registry
            : new RelayProviderRegistry($registry);
    }

    public function registry(): RelayProviderRegistry
    {
        return $this->registry;
    }

    public function has(string $provider): bool
    {
        return $this->registry->has($provider);
    }

    /** @return list<string> */
    public function allProviders(): array
    {
        return $this->registry->allProviders();
    }

    /** @return list<string> */
    public function models(string $provider): array
    {
        return array_keys($this->registry->provider($provider)['models'] ?? []);
    }

    public function defaultModel(string $provider): ?string
    {
        $model = $this->registry->provider($provider)['default_model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    public function modelInfo(string $provider, string $model): ModelInfo
    {
        $info = $this->registry->provider($provider)['models'][$model] ?? [];

        return new ModelInfo(
            displayName: isset($info['display_name']) ? (string) $info['display_name'] : null,
            contextWindow: (int) ($info['context'] ?? 0),
            maxOutput: (int) ($info['max_output'] ?? 0),
            thinking: (bool) ($info['thinking'] ?? false),
            inputPricePerMillion: isset($info['input']) ? (float) $info['input'] : (isset($info['input_price']) ? (float) $info['input_price'] : null),
            outputPricePerMillion: isset($info['output']) ? (float) $info['output'] : (isset($info['output_price']) ? (float) $info['output_price'] : null),
            cachedInputPricePerMillion: isset($info['cached_input']) ? (float) $info['cached_input'] : null,
            cachedWritePricePerMillion: isset($info['cached_write']) ? (float) $info['cached_write'] : null,
            pricingKind: (string) ($info['pricing_kind'] ?? 'paid'),
            referenceInputPricePerMillion: isset($info['reference_input'])
                ? (float) $info['reference_input']
                : (isset($info['reference_input_price']) ? (float) $info['reference_input_price'] : null),
            referenceOutputPricePerMillion: isset($info['reference_output'])
                ? (float) $info['reference_output']
                : (isset($info['reference_output_price']) ? (float) $info['reference_output_price'] : null),
            status: isset($info['status']) ? (string) $info['status'] : null,
        );
    }
}
