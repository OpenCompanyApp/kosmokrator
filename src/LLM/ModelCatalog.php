<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

class ModelCatalog
{
    /** @var array<string, array{context: int, input_price: float, output_price: float}> */
    private array $models;

    private array $default;

    public function __construct(array $config)
    {
        $this->models = $config['models'] ?? [];
        $this->default = $config['default'] ?? [
            'context' => 128_000,
            'input_price' => 3.0,
            'output_price' => 15.0,
        ];
    }

    public function contextWindow(string $model): int
    {
        $spec = $this->resolve($model);

        return (int) ($spec['context'] ?? $this->default['context']);
    }

    public function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $spec = $this->resolve($model);
        $inRate = (float) ($spec['input_price'] ?? $this->default['input_price']);
        $outRate = (float) ($spec['output_price'] ?? $this->default['output_price']);

        return round(($tokensIn * $inRate / 1_000_000) + ($tokensOut * $outRate / 1_000_000), 4);
    }

    public function supportsThinking(string $model): bool
    {
        return (bool) ($this->resolve($model)['thinking'] ?? false);
    }

    public function supportsStreaming(string $model): bool
    {
        return (bool) ($this->resolve($model)['streaming'] ?? true);
    }

    /**
     * Resolve model spec — tries exact match first, then substring match.
     */
    private function resolve(string $model): array
    {
        $key = strtolower($model);

        // Exact match
        if (isset($this->models[$key])) {
            return $this->models[$key];
        }

        // Substring match (e.g. "z/GLM-5.1" matches "glm-5.1")
        foreach ($this->models as $name => $spec) {
            if (str_contains($key, strtolower($name))) {
                return $spec;
            }
        }

        return $this->default;
    }
}
