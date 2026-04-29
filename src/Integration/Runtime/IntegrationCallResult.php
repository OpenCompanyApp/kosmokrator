<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

final readonly class IntegrationCallResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $function,
        public mixed $data,
        public bool $success,
        public ?string $error = null,
        public array $meta = [],
        public float $durationMs = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'function' => $this->function,
            'success' => $this->success,
            'data' => $this->data,
            'error' => $this->error,
            'meta' => $this->meta,
            'duration_ms' => $this->durationMs,
        ];
    }
}
