<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class ThinkingDelta extends AgentEvent
{
    public function __construct(
        public readonly string $text,
        ?float $timestamp = null,
    ) {
        parent::__construct('thinking_delta', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['text' => $this->text, 'content' => $this->text];
    }
}
