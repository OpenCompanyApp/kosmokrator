<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Per-depth model/provider overrides for subagents.
 *
 * Resolution cascades: depth-2+ overrides fall back to depth-1 overrides,
 * which fall back to the main agent defaults.
 *
 * @param  string  $defaultProvider  Main agent provider (always required)
 * @param  string  $defaultModel  Main agent model (always required)
 * @param  string  $defaultApiKey  Main agent API key
 * @param  string  $defaultBaseUrl  Main agent base URL
 * @param  string|null  $subagentProvider  Override provider for depth-1 subagents
 * @param  string|null  $subagentModel  Override model for depth-1 subagents
 * @param  string|null  $subagentApiKey  Override API key for depth-1 subagents
 * @param  string|null  $subagentBaseUrl  Override base URL for depth-1 subagents
 * @param  string|null  $depth2Provider  Override provider for depth-2+ subagents
 * @param  string|null  $depth2Model  Override model for depth-2+ subagents
 * @param  string|null  $depth2ApiKey  Override API key for depth-2+ subagents
 * @param  string|null  $depth2BaseUrl  Override base URL for depth-2+ subagents
 */
final readonly class SubagentModelConfig
{
    public function __construct(
        public string $defaultProvider,
        public string $defaultModel,
        public string $defaultApiKey,
        public string $defaultBaseUrl,
        public ?string $subagentProvider = null,
        public ?string $subagentModel = null,
        public ?string $subagentApiKey = null,
        public ?string $subagentBaseUrl = null,
        public ?string $depth2Provider = null,
        public ?string $depth2Model = null,
        public ?string $depth2ApiKey = null,
        public ?string $depth2BaseUrl = null,
    ) {}

    /**
     * Resolve the effective provider for the given agent depth.
     *
     * Depth 0 (root) is not expected here — this is called for subagents only.
     * Depth 1: subagent override → default.
     * Depth 2+: depth-2 override → subagent override → default.
     */
    public function resolveProvider(int $depth): string
    {
        if ($depth >= 2 && $this->depth2Provider !== null && $this->depth2Provider !== '') {
            return $this->depth2Provider;
        }

        if ($this->subagentProvider !== null && $this->subagentProvider !== '') {
            return $this->subagentProvider;
        }

        return $this->defaultProvider;
    }

    /**
     * Resolve the effective model for the given agent depth.
     */
    public function resolveModel(int $depth): string
    {
        if ($depth >= 2 && $this->depth2Model !== null && $this->depth2Model !== '') {
            return $this->depth2Model;
        }

        if ($this->subagentModel !== null && $this->subagentModel !== '') {
            return $this->subagentModel;
        }

        return $this->defaultModel;
    }

    /**
     * Resolve the effective API key for the given agent depth.
     */
    public function resolveApiKey(int $depth): string
    {
        if ($depth >= 2 && $this->depth2ApiKey !== null && $this->depth2ApiKey !== '') {
            return $this->depth2ApiKey;
        }

        if ($this->subagentApiKey !== null && $this->subagentApiKey !== '') {
            return $this->subagentApiKey;
        }

        return $this->defaultApiKey;
    }

    /**
     * Resolve the effective base URL for the given agent depth.
     */
    public function resolveBaseUrl(int $depth): string
    {
        if ($depth >= 2 && $this->depth2BaseUrl !== null && $this->depth2BaseUrl !== '') {
            return $this->depth2BaseUrl;
        }

        if ($this->subagentBaseUrl !== null && $this->subagentBaseUrl !== '') {
            return $this->subagentBaseUrl;
        }

        return $this->defaultBaseUrl;
    }
}
