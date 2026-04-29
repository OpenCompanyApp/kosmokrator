<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class TextDelta extends AgentEvent
{
    public function __construct(
        public readonly string $text,
        ?float $timestamp = null,
    ) {
        parent::__construct('text_delta', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['text' => $this->text, 'delta' => $this->text];
    }
}
