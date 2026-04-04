<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiConsensus;

class ConsensusCommand implements PowerCommand
{
    public function name(): string
    {
        return ':consensus';
    }

    public function aliases(): array
    {
        return [':council'];
    }

    public function description(): string
    {
        return 'Quality gate: Planner → Architect → Critic deliberation loop';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiConsensus::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            CONSENSUS MODE — COUNCIL DELIBERATION.

            Task: "{$args}"

            Before any implementation, run a structured deliberation loop. Three expert roles review the task sequentially — each sees the accumulated feedback from previous reviewers.

            ## Round 1: The Council

            ### Planner (runs first)
            Role: Requirements analyst.
            - Clarify what exactly needs to happen
            - Define acceptance criteria (numbered, testable)
            - Identify scope boundaries
            - List assumptions and risks
            - Output: **Requirements Brief** (max 1 page)

            ### Architect (runs second, sees Planner output)
            Role: Technical designer.
            - Review the Planner's requirements for feasibility
            - Design the implementation approach
            - Identify at least 2 alternative approaches with tradeoffs
            - Flag any requirements that are ambiguous or conflicting
            - Estimate complexity and risk per component
            - Output: **Architecture Brief** with recommended approach and alternatives

            ### Critic (runs third, sees both Planner + Architect output)
            Role: Devil's advocate.
            - Challenge assumptions in both briefs
            - Identify edge cases neither addressed
            - Question the recommended approach — is there a simpler way?
            - Check: are the acceptance criteria actually testable?
            - Check: does the architecture over-engineer or under-engineer?
            - Flag anything that would fail code review
            - Output: **Critique** with specific objections and suggestions

            ## Round 2: Resolution (if needed)

            If the Critic raised substantive objections:
            - Address each objection explicitly
            - Revise the requirements and architecture where the Critic was right
            - Justify where the Critic was wrong (with evidence)
            - Produce a **Revised Plan** that incorporates valid criticism

            If the Critic found no substantive issues:
            - Skip Round 2
            - The original plan stands

            ## Final Output: Consensus Document

            ```
            ═══ CONSENSUS REACHED ═══

            ## Requirements (from Planner, refined by Critic)
            <numbered acceptance criteria>

            ## Approach (from Architect, validated by Critic)
            <recommended approach with rationale>

            ## Alternatives Considered
            <why they were rejected>

            ## Risks & Mitigations
            <from all three reviewers>

            ## Implementation Plan
            <ordered steps with file paths>
            ```

            ## Rules:
            - Each role runs SEQUENTIALLY (not parallel) so it can see accumulated context
            - The Critic must find at least one concern (force critical thinking)
            - If the task is trivially simple, say so and skip the full council
            - The consensus document is the deliverable — do not start implementation
            - Quality gate: 80%+ of acceptance criteria must be testable
            PROMPT;
    }
}
