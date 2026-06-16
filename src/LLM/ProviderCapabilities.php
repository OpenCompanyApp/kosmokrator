<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final class ProviderCapabilities
{
    /** @return array<string, array<string, bool>> */
    public static function defaults(): array
    {
        return [
            'z' => ['temperature' => false, 'top_p' => false, 'max_tokens' => true, 'streaming' => false, 'stream_usage' => false],
            'z-api' => ['temperature' => false, 'top_p' => false, 'max_tokens' => true, 'streaming' => true, 'stream_usage' => true],
            'kimi-coding' => ['temperature' => false, 'top_p' => false, 'max_tokens' => true, 'streaming' => true, 'stream_usage' => true],
            'ollama' => ['temperature' => true, 'top_p' => true, 'max_tokens' => true, 'streaming' => true, 'stream_usage' => false],
            'codex' => ['temperature' => false, 'top_p' => false, 'max_tokens' => true, 'streaming' => true, 'stream_usage' => true],
        ];
    }

    public static function for(string $provider, ?RelayProviderRegistry $registry = null): self
    {
        $caps = $registry?->capabilities($provider) ?? (self::defaults()[$provider] ?? []);

        return new self($caps);
    }

    /** @param array<string, bool> $capabilities */
    public function __construct(private readonly array $capabilities) {}

    public function supportsTemperature(): bool
    {
        return $this->capabilities['temperature'] ?? true;
    }

    public function supportsStreaming(): bool
    {
        return $this->capabilities['streaming'] ?? true;
    }
}
