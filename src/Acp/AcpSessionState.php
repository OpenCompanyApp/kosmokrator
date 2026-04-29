<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use Kosmokrator\Agent\AgentSession;

final class AcpSessionState
{
    /**
     * @param  list<array<string, mixed>>  $mcpServers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $cwd,
        public readonly AgentSession $session,
        public readonly AcpRenderer $renderer,
        public array $mcpServers = [],
    ) {}
}
