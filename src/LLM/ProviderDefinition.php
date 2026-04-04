<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * Immutable value object describing a single LLM provider for the settings UI.
 *
 * Created by ProviderCatalog from RelayProviderRegistry and ProviderMeta data.
 * Contains the provider's identity, auth mode, driver, URL, default model, and
 * the full list of ModelDefinition objects available under this provider.
 * Used for provider selection and model browsing in the TUI.
 */
final readonly class ProviderDefinition
{
    /**
     * @param  string  $id  Provider identifier (e.g. "anthropic", "openai")
     * @param  string  $label  Human-readable display name
     * @param  string  $description  One-line description for the settings UI
     * @param  string  $authMode  Authentication type: "api_key", "oauth", or "none"
     * @param  string  $source  "built_in" or "custom" (user-defined provider)
     * @param  string  $driver  Prism driver name (e.g. "anthropic", "openai", "codex")
     * @param  string  $url  Base API URL for the provider
     * @param  string  $defaultModel  Default model ID when none is explicitly selected
     * @param  list<ModelDefinition>  $models  All models available under this provider
     * @param  list<string>  $inputModalities  Supported input types at the provider level
     * @param  list<string>  $outputModalities  Supported output types at the provider level
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $authMode,
        public string $source,
        public string $driver,
        public string $url,
        public string $defaultModel,
        public array $models,
        public array $inputModalities = ['text'],
        public array $outputModalities = ['text'],
    ) {}
}
