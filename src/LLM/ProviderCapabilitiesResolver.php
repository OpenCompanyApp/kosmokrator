<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final class ProviderCapabilitiesResolver
{
    public function __construct(
        private readonly RelayProviderRegistry $providers,
    ) {}

    public function supportsTemperature(string $provider): bool
    {
        return $this->providers->capabilities($provider)['temperature'];
    }

    public function supportsTopP(string $provider): bool
    {
        return $this->providers->capabilities($provider)['top_p'];
    }

    public function supportsMaxTokens(string $provider): bool
    {
        return $this->providers->capabilities($provider)['max_tokens'];
    }

    public function supportsStreaming(string $provider): bool
    {
        return $this->providers->capabilities($provider)['streaming'];
    }
}
