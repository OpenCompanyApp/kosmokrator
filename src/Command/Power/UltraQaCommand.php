<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiUltraQa;

class UltraQaCommand implements PowerCommand
{
    public function name(): string
    {
        return ':ultraqa';
    }

    public function aliases(): array
    {
        return [':qa'];
    }

    public function description(): string
    {
        return 'Autonomous QA cycling until all tests pass';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiUltraQa::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? "\n\nFocus: {$args}" : '';

        return <<<PROMPT
            ULTRA QA — AUTONOMOUS TEST CYCLING.{$scope}

            Run the complete QA cycle: test → analyze → fix → re-test. Repeat up to 5 cycles or until everything passes.

            ## Cycle N:

            1. **RUN**: Execute the full test suite. Capture all output.

            2. **ANALYZE**: For each failure:
               - Identify the root cause (is it a real bug, a test issue, or a flaky test?)
               - Categorize: code-bug, test-bug, environment-issue, flaky
               - Prioritize: fix code-bugs first, then test-bugs

            3. **FIX**: Apply fixes. For each fix:
               - State what was wrong and why
               - Show the change
               - Explain why this fix is correct

            4. **RE-TEST**: Run the test suite again.
               - If all pass → done, report results
               - If failures remain → analyze new failures, go to next cycle
               - Each cycle should have FEWER failures than the previous one

            ## Rules:
            - Each cycle MUST reduce the failure count. If a cycle introduces new failures, revert that cycle's changes and try a different approach.
            - Never modify tests just to make them pass (unless the test itself is genuinely wrong).
            - After 3 cycles, re-evaluate: is there a systemic issue causing cascading failures?
            - After 5 cycles, report remaining failures and analysis, even if not all pass.
            - Track metrics: failures per cycle, to show convergence.

            ## Output:
            Final report:
            - Starting state: N tests, M failures
            - Cycles taken
            - Per-cycle: failures fixed, new issues found
            - Final state: all pass / N remaining failures with analysis
            PROMPT;
    }
}
