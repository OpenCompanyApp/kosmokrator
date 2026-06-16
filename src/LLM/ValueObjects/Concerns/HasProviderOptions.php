<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects\Concerns;

trait HasProviderOptions
{
    /** @var array<string, mixed> */
    private array $providerOptions = [];

    /**
     * @param  array<string, mixed>|null  $options
     */
    public function withProviderOptions(?array $options): static
    {
        $this->providerOptions = $options ?? [];

        return $this;
    }

    /**
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function providerOptions(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->providerOptions;
        }

        return $this->providerOptions[$key] ?? null;
    }
}
