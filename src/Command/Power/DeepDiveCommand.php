<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiDeepDive;

class DeepDiveCommand implements PowerCommand
{
    public function name(): string
    {
        return ':deepdive';
    }

    public function aliases(): array
    {
        return [':dive'];
    }

    public function description(): string
    {
        return 'Deep investigation: trace the WHY, then define the WHAT';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiDeepDive::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            DEEP DIVE — TRACE THE WHY, DEFINE THE WHAT.

            Subject: "{$args}"

            This is a two-phase investigation. Phase 1 discovers root causes. Phase 2 uses those discoveries to gather precise requirements. The phases are connected — Phase 1 findings inject directly into Phase 2.

            ## Phase 1: TRACE (Why is this happening?)

            Launch 3 parallel investigation lanes as background agents:

            **Lane A — Code Path Trace:**
            Follow the execution path related to the subject. Read the relevant files, trace the data flow, identify where behavior diverges from expectation. Report the exact mechanism.

            **Lane B — History Trace:**
            Check git log/blame for relevant changes. When was the relevant code last modified? What was the intent of those changes? Were there related issues/PRs? Report the evolution.

            **Lane C — Pattern Trace:**
            Search the broader codebase for similar patterns. Is this issue systemic or isolated? Are there other instances of the same pattern that work correctly? What's different? Report the pattern analysis.

            Wait for all 3 lanes to complete. Then synthesize:
            - **Root cause** (one sentence, high confidence)
            - **Contributing factors** (bullet list)
            - **Evidence summary** (key files and lines from each lane)
            - **Confidence level** and what would increase it

            ## Phase 2: INTERVIEW (What exactly should we do?)

            Using the Phase 1 findings as context, now gather precise requirements:

            **Inject into requirements gathering:**
            - The confirmed root cause narrows the solution space
            - The code path trace tells us exactly what needs to change
            - The history trace reveals constraints and past decisions to respect
            - The pattern analysis shows whether the fix should be local or systemic

            **Ask targeted questions about:**
            1. Given the root cause is X, should we fix it at the source or add a guard downstream?
            2. The pattern analysis found N similar instances — should we fix all of them or just this one?
            3. Past changes suggest constraint Y — is that still valid?
            4. What's the acceptance criterion? How will we know this is fixed?

            Score ambiguity on each dimension. If ambiguity < 20% from Phase 1 alone, skip to producing the requirements document.

            ## Output:
            A requirements document that combines:
            - Root cause analysis (from Phase 1)
            - Clear requirements with acceptance criteria (from Phase 2)
            - Specific files and lines to modify
            - Risks and constraints from historical analysis
            PROMPT;
    }
}
