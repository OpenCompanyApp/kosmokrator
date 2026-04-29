<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class ToolCallCompleted extends AgentEvent
{
    public function __construct(
        public readonly string $tool,
        public readonly string $output,
        public readonly bool $success,
        public readonly ?string $toolUseId = null,
        ?float $timestamp = null,
    ) {
        parent::__construct('tool_call_completed', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'tool' => $this->tool,
            'name' => $this->tool,
            'output' => $this->output,
            'success' => $this->success,
            'tool_use_id' => $this->toolUseId,
        ];
    }
}
