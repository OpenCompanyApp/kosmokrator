<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class UsageUpdated extends AgentEvent
{
    public function __construct(
        public readonly string $model,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly float $cost,
        public readonly int $maxContext,
        ?float $timestamp = null,
    ) {
        parent::__construct('usage_updated', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost' => $this->cost,
            'max_context' => $this->maxContext,
        ];
    }
}
