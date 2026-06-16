<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

readonly class ContextSuggestion
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public string $action,
    ) {}
}
