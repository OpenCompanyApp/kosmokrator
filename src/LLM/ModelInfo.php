<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final readonly class ModelInfo
{
    public function __construct(
        public ?string $displayName,
        public int $contextWindow,
        public int $maxOutput,
        public bool $thinking = false,
        public ?float $inputPricePerMillion = null,
        public ?float $outputPricePerMillion = null,
        public ?float $cachedInputPricePerMillion = null,
        public ?float $cachedWritePricePerMillion = null,
        public string $pricingKind = 'paid',
        public ?float $referenceInputPricePerMillion = null,
        public ?float $referenceOutputPricePerMillion = null,
        public ?string $status = null,
    ) {}
}
