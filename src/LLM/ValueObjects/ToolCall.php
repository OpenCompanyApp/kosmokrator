<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects;

final class ToolCall
{
    /** @param array<string, mixed>|string $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array|string $arguments,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    public function arguments(): array
    {
        if (is_array($this->arguments)) {
            return $this->arguments;
        }

        $decoded = json_decode($this->arguments, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
