<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Immutable value object holding all context-management components
 * wired by ContextPipelineFactory and consumed by AgentSessionBuilder.
 */
readonly class ContextPipeline
{
    public function __construct(
        public ContextBudget $budget,
        public ?ContextCompactor $compactor,
        public ?ContextPruner $pruner,
        public ?ToolResultDeduplicator $deduplicator,
        public ?OutputTruncator $truncator,
        public ?ProtectedContextBuilder $protectedContextBuilder,
    ) {}
}
