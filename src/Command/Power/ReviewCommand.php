<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiReview;

class ReviewCommand implements PowerCommand
{
    public function name(): string
    {
        return ':review';
    }

    public function aliases(): array
    {
        return [':cr'];
    }

    public function description(): string
    {
        return 'Parallel code review across 4 dimensions with verification';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiReview::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? $args : 'all uncommitted changes (staged + unstaged)';

        return <<<PROMPT
            CODE REVIEW — PARALLEL MULTI-DIMENSIONAL INSPECTION.

            Scope: {$scope}

            ## Step 1: Determine Scope
            - If a PR number was given, fetch it with `gh pr diff <number>`
            - If a file path was given, review that file
            - Otherwise, review all local changes: `git diff` + `git diff --cached`
            - Read the changed files in full for context (not just the diff)

            ## Step 2: Launch 4 Parallel Review Agents
            Spawn 4 background agents, each reviewing the SAME changes but from a different dimension:

            **Agent 1 — Correctness:**
            - Logic errors, off-by-one, null handling, type mismatches
            - Missing edge cases, incomplete implementations
            - Incorrect API usage, wrong method signatures

            **Agent 2 — Security:**
            - Injection vulnerabilities (SQL, command, XSS)
            - Authentication/authorization gaps
            - Sensitive data exposure, insecure defaults
            - OWASP Top 10 patterns

            **Agent 3 — Code Quality:**
            - Naming clarity, readability, maintainability
            - DRY violations, unnecessary complexity
            - Missing or excessive abstractions
            - Consistency with codebase conventions

            **Agent 4 — Performance:**
            - N+1 queries, unnecessary allocations
            - Missing caching opportunities
            - Algorithmic complexity issues
            - Resource leaks, blocking I/O in async contexts

            Give each agent the exact commands to obtain the diff (don't paste the diff 4 times).
            Each agent must return findings as structured JSON:
            ```
            [{"file": "path", "line": N, "severity": "critical|warning|info", "dimension": "...", "finding": "...", "suggestion": "..."}]
            ```

            ## Step 3: Verify & Deduplicate
            After all agents complete:
            - Deduplicate findings that point to the same issue
            - For each critical/warning finding, verify it against the actual code (reject false positives)
            - When uncertain, lean toward rejection (high signal, low noise)

            ## Step 4: Present Results
            Output a structured review:
            ```
            ═══ CODE REVIEW RESULTS ═══
            Scope: <what was reviewed>
            Findings: N critical, N warnings, N info

            CRITICAL:
            ✗ file.php:42 — [Security] SQL injection via unsanitized input
              Suggestion: Use parameterized query

            WARNINGS:
            ⚠ file.php:89 — [Quality] Method too long (85 lines)
              Suggestion: Extract helper method

            INFO:
            ℹ file.php:12 — [Perf] Consider caching this lookup
            ```

            ## Exclusion Criteria — Do NOT flag:
            - Pre-existing issues not introduced by this change
            - Style preferences already handled by linters
            - Pedantic nitpicks that don't affect correctness
            - Test files (unless the test itself is wrong)
            PROMPT;
    }
}
