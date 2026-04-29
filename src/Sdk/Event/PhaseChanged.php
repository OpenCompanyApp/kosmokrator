<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class PhaseChanged extends AgentEvent
{
    public function __construct(
        public readonly string $phase,
        ?float $timestamp = null,
    ) {
        parent::__construct('phase_changed', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['phase' => $this->phase];
    }
}
