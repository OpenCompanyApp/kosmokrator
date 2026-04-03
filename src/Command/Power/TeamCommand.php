<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiTeam;

class TeamCommand implements PowerCommand
{
    public function name(): string
    {
        return ':team';
    }

    public function aliases(): array
    {
        return [':squad'];
    }

    public function description(): string
    {
        return 'Staged pipeline with specialized agent roles';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiTeam::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            TEAM PROTOCOL — STAGED PIPELINE WITH ROLE SPECIALIZATION.

            Task: "{$args}"

            Execute a 5-stage pipeline where each stage is a specialized role. Each stage produces a structured handoff document that preserves decisions, alternatives, and risks for the next stage.

            ## Stage 1: PLANNER
            Role: Requirements analyst and project scoper.
            - Clarify the goal, constraints, and success criteria
            - Identify stakeholders and their needs
            - Define scope boundaries (what's in, what's out)
            - Output: **Requirements Document** with numbered acceptance criteria

            ## Stage 2: ARCHITECT
            Role: System designer and technical decision-maker.
            - Design the implementation approach
            - Evaluate alternatives (list at least 2, explain tradeoffs)
            - Identify risks and mitigation strategies
            - Define the component structure and interfaces
            - Output: **Architecture Document** with file list, design decisions, and risk register

            ## Stage 3: EXECUTOR
            Role: Implementation engineer.
            - Follow the Architecture Document precisely
            - Write clean, production-quality code
            - Make tactical decisions within the architectural framework
            - Document any deviations from the plan and why
            - Output: **Implementation Report** listing all changes made

            ## Stage 4: VERIFIER
            Role: QA engineer and code reviewer.
            - Run all tests. Write new tests for new functionality.
            - Review code against the Architecture Document
            - Check each acceptance criterion from the Requirements Document
            - Output: **Verification Report** with pass/fail per criterion and issues found

            ## Stage 5: FIXER
            Role: Issue resolver (only runs if Verifier found issues).
            - Address each issue from the Verification Report
            - Re-run verification for fixed items
            - Output: **Fix Report** with resolution details

            ## Handoff Rules:
            - Each stage MUST read the outputs of all previous stages before starting.
            - Clearly mark stage transitions: "═══ STAGE 2: ARCHITECT ═══"
            - If a stage discovers the previous stage missed something critical, note it and adapt.
            - Preserve the chain of reasoning — never discard context from earlier stages.
            PROMPT;
    }
}
