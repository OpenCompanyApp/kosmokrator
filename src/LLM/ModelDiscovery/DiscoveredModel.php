<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ModelDiscovery;

final readonly class DiscoveredModel
{
    /**
     * @param  list<string>  $inputModalities
     * @param  list<string>  $outputModalities
     */
    public function __construct(
        public string $id,
        public string $displayName = '',
        public int $contextWindow = 0,
        public int $maxOutput = 0,
        public bool $thinking = false,
        public ?float $inputPricePerMillion = null,
        public ?float $outputPricePerMillion = null,
        public ?string $status = null,
        public array $inputModalities = [],
        public array $outputModalities = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'context_window' => $this->contextWindow,
            'max_output' => $this->maxOutput,
            'thinking' => $this->thinking,
            'input_price_per_million' => $this->inputPricePerMillion,
            'output_price_per_million' => $this->outputPricePerMillion,
            'status' => $this->status,
            'input_modalities' => $this->inputModalities,
            'output_modalities' => $this->outputModalities,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            displayName: trim((string) ($data['display_name'] ?? '')),
            contextWindow: max(0, (int) ($data['context_window'] ?? 0)),
            maxOutput: max(0, (int) ($data['max_output'] ?? 0)),
            thinking: (bool) ($data['thinking'] ?? false),
            inputPricePerMillion: is_numeric($data['input_price_per_million'] ?? null) ? (float) $data['input_price_per_million'] : null,
            outputPricePerMillion: is_numeric($data['output_price_per_million'] ?? null) ? (float) $data['output_price_per_million'] : null,
            status: is_string($data['status'] ?? null) && $data['status'] !== '' ? $data['status'] : null,
            inputModalities: self::stringList($data['input_modalities'] ?? []),
            outputModalities: self::stringList($data['output_modalities'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }
}
