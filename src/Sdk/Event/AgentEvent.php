<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Event;

abstract class AgentEvent implements \JsonSerializable
{
    public readonly float $timestamp;

    public function __construct(
        public readonly string $type,
        ?float $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'timestamp' => $this->timestamp,
        ];
    }
}
