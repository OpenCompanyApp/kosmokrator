<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class SubagentSpawned extends AgentEvent
{
    /** @param list<array<string, mixed>> $entries */
    public function __construct(
        public readonly array $entries,
        ?float $timestamp = null,
    ) {
        parent::__construct('subagent_spawned', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['entries' => $this->entries];
    }
}
