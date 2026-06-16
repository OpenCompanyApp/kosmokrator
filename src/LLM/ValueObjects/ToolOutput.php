<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects;

final class ToolOutput
{
    public function __construct(
        public string $result,
    ) {}
}
