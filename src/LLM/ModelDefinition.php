<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final readonly class ModelDefinition
{
    public function __construct(
        public string $id,
        public string $displayName,
        public int $contextWindow,
        public int $maxOutput,
        public bool $thinking = false,
        public ?float $inputPricePerMillion = null,
        public ?float $outputPricePerMillion = null,
    ) {}

    public function label(): string
    {
        return $this->displayName !== '' && $this->displayName !== $this->id
            ? "{$this->displayName} ({$this->id})"
            : $this->id;
    }
}
