<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects;

final class ToolError
{
    public function __construct(
        public string $message,
    ) {}
}
