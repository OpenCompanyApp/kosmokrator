<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class ToolCallStarted extends AgentEvent
{
    /** @param array<string, mixed> $args */
    public function __construct(
        public readonly string $tool,
        public readonly array $args,
        public readonly ?string $toolUseId = null,
        ?float $timestamp = null,
    ) {
        parent::__construct('tool_call_started', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'tool' => $this->tool,
            'name' => $this->tool,
            'args' => $this->args,
            'tool_use_id' => $this->toolUseId,
        ];
    }
}
