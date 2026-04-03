<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiAutopilot;

class AutopilotCommand implements PowerCommand
{
    public function name(): string
    {
        return ':autopilot';
    }

    public function aliases(): array
    {
        return [':pilot', ':auto'];
    }

    public function description(): string
    {
        return 'Full autonomous pipeline from idea to verified working code';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiAutopilot::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            AUTOPILOT ENGAGED. Execute the full autonomous pipeline for this task:

            "{$args}"

            Run these 5 phases in strict sequence. Do NOT skip phases. Do NOT ask the user for input unless you encounter genuine ambiguity that blocks progress.

            ## Phase 1: EXPAND (Requirements)
            - Clarify what exactly needs to happen. Read relevant files to understand the current state.
            - Identify acceptance criteria — how will we know this is done?
            - List assumptions. If any assumption is risky, note it but proceed with the most reasonable interpretation.
            - Output: clear requirements with numbered acceptance criteria.

            ## Phase 2: PLAN (Architecture)
            - Design the implementation approach. Identify files to create/modify.
            - Consider edge cases, error handling, and integration points.
            - If the task is large, break it into ordered sub-tasks.
            - Output: numbered implementation steps with file paths.

            ## Phase 3: EXECUTE (Write Code)
            - Implement each step from the plan.
            - Write clean, production-quality code.
            - After each significant change, verify it doesn't break existing functionality.
            - If you hit an unexpected issue, adapt the plan and continue.

            ## Phase 4: QA (Test & Verify)
            - Run the test suite. If tests fail, fix them.
            - Manually verify the acceptance criteria from Phase 1.
            - Check for regressions: run a broader test sweep if available.
            - If tests don't exist for the new functionality, write them.

            ## Phase 5: VALIDATE (Acceptance)
            - Review each acceptance criterion from Phase 1.
            - For each one, state: PASS (with evidence) or FAIL (with explanation).
            - If any criterion fails, loop back to Phase 3 to fix it.
            - When all criteria pass, produce a summary of what was done.

            Rules:
            - Be autonomous. Only pause for user input on genuine blocking ambiguity.
            - If a phase fails, diagnose why, fix it, and re-run that phase.
            - Show phase transitions clearly: "═══ PHASE 2: PLAN ═══"
            - Track time: note when each phase starts and ends.
            PROMPT;
    }
}
