<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;

/**
 * Value object holding all components needed for an interactive agent session.
 */
readonly class AgentSession
{
    public function __construct(
        public UIManager $ui,
        public AgentLoop $agentLoop,
        public LlmClientInterface $llm,
        public PermissionEvaluator $permissions,
        public SessionManager $sessionManager,
        public ?SubagentOrchestrator $orchestrator,
    ) {}
}
