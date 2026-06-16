<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects;

final class ToolResult
{
    /**
     * @param  array<string, mixed>  $args
     * @param  int|float|string|array<mixed>|null  $result
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public int|float|string|array|null $result,
    ) {}
}
