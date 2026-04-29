<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

final class RunCompleted extends AgentEvent
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $sessionId,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $turns,
        public readonly int $toolCalls,
        public readonly float $elapsedSeconds,
        ?float $timestamp = null,
    ) {
        parent::__construct('run_completed', $timestamp);
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'text' => $this->text,
            'session_id' => $this->sessionId,
            'turns' => $this->turns,
            'tool_calls' => $this->toolCalls,
            'elapsed_seconds' => $this->elapsedSeconds,
            'usage' => [
                'tokens_in' => $this->tokensIn,
                'tokens_out' => $this->tokensOut,
            ],
        ];
    }
}
