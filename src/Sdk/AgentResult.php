<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Kosmokrator\Sdk\Event\AgentEvent;

final class AgentResult
{
    /**
     * @param  list<AgentEvent>  $events
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $sessionId,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $turns,
        public readonly int $toolCalls,
        public readonly float $elapsedSeconds,
        public readonly array $events = [],
        public readonly bool $success = true,
        public readonly int $exitCode = 0,
        public readonly ?string $error = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => 'result',
            'text' => $this->text,
            'session_id' => $this->sessionId,
            'duration_ms' => (int) round($this->elapsedSeconds * 1000),
            'turns' => $this->turns,
            'tool_calls' => $this->toolCalls,
            'success' => $this->success,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
            'usage' => [
                'tokens_in' => $this->tokensIn,
                'tokens_out' => $this->tokensOut,
            ],
            'events' => array_map(
                static fn (AgentEvent $event): array => $event->jsonSerialize(),
                $this->events,
            ),
        ];
    }
}
