<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * Thin adapter that queries RelayProviderRegistry for per-provider capability flags.
 *
 * Provides boolean checks for temperature, top_p, max_tokens, and streaming support.
 * Used by PrismService and AsyncLlmClient to conditionally include parameters that
 * some providers (e.g. Ollama) do not support.
 */
final class ProviderCapabilitiesResolver
{
    public function __construct(
        private readonly RelayProviderRegistry $providers,
    ) {}

    /** @param string $provider Provider identifier */
    public function supportsTemperature(string $provider): bool
    {
        return $this->providers->capabilities($provider)['temperature'];
    }

    /** @param string $provider Provider identifier */
    public function supportsTopP(string $provider): bool
    {
        return $this->providers->capabilities($provider)['top_p'];
    }

    /** @param string $provider Provider identifier */
    public function supportsMaxTokens(string $provider): bool
    {
        return $this->providers->capabilities($provider)['max_tokens'];
    }

    /** @param string $provider Provider identifier */
    public function supportsStreaming(string $provider): bool
    {
        return $this->providers->capabilities($provider)['streaming'];
    }
}
