<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Immutable value object holding all subagent infrastructure components
 * wired by SubagentPipelineFactory and consumed by AgentSessionBuilder.
 */
readonly class SubagentPipeline
{
    public function __construct(
        public SubagentOrchestrator $orchestrator,
        public AgentContext $rootContext,
        public SubagentFactory $factory,
    ) {}
}
