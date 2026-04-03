<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiDeslop;

class DeslopCommand implements PowerCommand
{
    public function name(): string
    {
        return ':deslop';
    }

    public function aliases(): array
    {
        return [':clean'];
    }

    public function description(): string
    {
        return 'Regression-safe cleanup of AI-generated bloat';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiDeslop::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? "\n\nScope: {$args}" : '';

        return <<<PROMPT
            DESLOP MODE — PURIFICATION PROTOCOL.{$scope}

            Review the codebase (or the specified scope) for AI-generated slop and clean it up. This is a deletion-first workflow: remove before rewriting.

            ## What to look for:

            1. **Unnecessary abstractions** — Interfaces with a single implementation. Abstract classes that could be concrete. Factory classes that create one thing. Wrapper classes that just delegate.

            2. **Over-engineering** — Generic solutions to specific problems. Configuration for things that never change. Plugin systems with one plugin. Strategy patterns with one strategy.

            3. **Dead code** — Unused functions, classes, imports, variables. Methods only called from deleted code. Feature flags that are always on/off.

            4. **Excessive comments** — Comments that restate the code. PHPDoc that adds no information beyond the type signature. "This method does X" where X is the method name. Section dividers in small files.

            5. **Unnecessary error handling** — Try/catch that re-throws unchanged. Null checks on values that can't be null. Validation of internal-only data. Defensive copies of immutable data.

            6. **Bloated tests** — Tests that test the framework, not the code. Mocks of things that could just be used directly. Assertion messages that duplicate the assertion.

            ## Protocol:

            1. SCAN: Read through the target files. Identify each instance of slop.
            2. ASSESS: For each instance, rate severity (noise / minor / major) and risk of removal (safe / needs-test / risky).
            3. RUN TESTS: Before any changes, run the test suite to establish a baseline.
            4. DELETE: Remove the safe+major items first. Run tests after each deletion batch.
            5. SIMPLIFY: For items that can't just be deleted, simplify them. Run tests.
            6. REPORT: List what was removed, what was simplified, and what was left alone (with reasons).

            Rules:
            - NEVER add code. Only delete or simplify.
            - Run tests between every batch of changes. If tests break, revert that batch.
            - If unsure, leave it. Better to miss some slop than to break something.
            - Preserve meaningful abstractions and intentional design patterns.
            PROMPT;
    }
}
