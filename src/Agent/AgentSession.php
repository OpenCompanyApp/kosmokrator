<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\RendererInterface;

/**
 * Immutable value object holding all wired components for an agent session.
 * Built by AgentSessionBuilder and consumed by AgentCommand to drive the REPL or headless execution.
 *
 * @see AgentSessionBuilder
 * @see AgentLoop
 */
readonly class AgentSession
{
    public function __construct(
        public RendererInterface $ui,
        public AgentLoop $agentLoop,
        public LlmClientInterface $llm,
        public PermissionEvaluator $permissions,
        public SessionManager $sessionManager,
        public ?SubagentOrchestrator $orchestrator,
    ) {}
}
