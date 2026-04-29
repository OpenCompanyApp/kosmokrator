<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class ErrorEvent extends AgentEvent
{
    public function __construct(
        public readonly string $message,
        ?float $timestamp = null,
    ) {
        parent::__construct('error', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['message' => $this->message];
    }
}
