<?php

declare(strict_types=1);

namespace Kosmokrator\Lua;

readonly class LuaResult
{
    public function __construct(
        public string $output,
        public ?string $error,
        public mixed $result,
        public float $executionTime,
        public ?int $memoryUsage,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'error' => $this->error,
            'result' => $this->result,
            'executionTime' => $this->executionTime,
            'memoryUsage' => $this->memoryUsage,
        ];
    }
}
