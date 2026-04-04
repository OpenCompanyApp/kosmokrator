<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * Immutable value object describing a single LLM model's capabilities and pricing.
 *
 * Created by ProviderCatalog when building the provider/model selection UI.
 * Carries context window, max output, pricing rates, modalities, and feature flags
 * (thinking, streaming) for display and cost estimation in ModelCatalog.
 */
final readonly class ModelDefinition
{
    /**
     * @param  string  $id  Machine-readable model identifier (e.g. "claude-sonnet-4-20250514")
     * @param  string  $displayName  Human-friendly name for UI display
     * @param  int  $contextWindow  Maximum context length in tokens
     * @param  int  $maxOutput  Maximum output tokens per request
     * @param  bool  $thinking  Whether the model supports extended thinking/reasoning
     * @param  float|null  $inputPricePerMillion  Price per 1M input tokens in USD
     * @param  float|null  $outputPricePerMillion  Price per 1M output tokens in USD
     * @param  string  $pricingKind  Pricing model: "paid", "token_plan", "coding_plan", "public_free"
     * @param  float|null  $referenceInputPricePerMillion  Reference input price for coding-plan display
     * @param  float|null  $referenceOutputPricePerMillion  Reference output price for coding-plan display
     * @param  string|null  $status  Model status (e.g. "active", "deprecated")
     * @param  list<string>  $inputModalities  Supported input types (e.g. ["text", "image"])
     * @param  list<string>  $outputModalities  Supported output types (e.g. ["text"])
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public int $contextWindow,
        public int $maxOutput,
        public bool $thinking = false,
        public ?float $inputPricePerMillion = null,
        public ?float $outputPricePerMillion = null,
        public string $pricingKind = 'paid',
        public ?float $referenceInputPricePerMillion = null,
        public ?float $referenceOutputPricePerMillion = null,
        public ?string $status = null,
        public array $inputModalities = ['text'],
        public array $outputModalities = ['text'],
    ) {}

    /**
     * Human-readable label for display: "Display Name (model-id)" when they differ, or just the id.
     */
    public function label(): string
    {
        return $this->displayName !== '' && $this->displayName !== $this->id
            ? "{$this->displayName} ({$this->id})"
            : $this->id;
    }
}
