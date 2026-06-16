<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Schema;

final class StringSchema
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {}
}
