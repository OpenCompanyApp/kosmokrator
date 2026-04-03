<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiRalph;

class RalphCommand implements PowerCommand
{
    public function name(): string
    {
        return ':ralph';
    }

    public function aliases(): array
    {
        return [':sisyphus', ':persist'];
    }

    public function description(): string
    {
        return 'Persistent retry loop — the boulder never stops';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiRalph::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            RALPH MODE — THE BOULDER NEVER STOPS.

            Your task: "{$args}"

            You will attempt this task repeatedly until it is VERIFIED COMPLETE. This is a persistence loop with structured retry logic.

            ## Protocol:

            ### Attempt N (start at 1, max 5):

            1. **EXECUTE**: Implement/fix the task. Be thorough — use everything you learned from previous attempts.

            2. **VERIFY**: Run concrete verification:
               - Run the test suite (if applicable)
               - Check that the specific acceptance criteria are met
               - Verify no regressions were introduced
               - Read the changed files and confirm they look correct

            3. **ASSESS**: Is the task complete?
               - If ALL checks pass → proceed to Self-Review
               - If ANY check fails → analyze the failure, learn from it, increment N, go to step 1

            ### Self-Review (after all checks pass):
            Before declaring done, do a mandatory architect review of your own work:
            - Is this the simplest solution that meets the requirements?
            - Are there edge cases not covered?
            - Would this survive a code review?
            - Is the code clean, readable, and well-tested?

            If the self-review reveals issues, fix them (counts as a new attempt).

            ## Retry Rules:
            - Each attempt MUST try a different approach if the previous one failed.
            - Document what you learned from each failure: "Attempt N failed because X. Changing approach to Y."
            - After 3 failures, step back and reconsider the entire approach from scratch.
            - After 5 failures, report what you've learned and ask the user for guidance.
            - NEVER give up before the max retry limit unless the task is provably impossible.

            ## Output:
            After successful completion, summarize:
            - Number of attempts taken
            - What failed and why (for each failed attempt)
            - Final solution and verification results
            PROMPT;
    }
}
