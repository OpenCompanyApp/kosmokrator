<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched after a message is saved to session storage.
 * Enables external listeners to react to conversation persistence
 * (e.g. backup, replication, audit logging).
 */
readonly class MessagePersisted
{
    public function __construct(
        public string $role,
        public int $tokensIn,
        public int $tokensOut,
    ) {}
}
