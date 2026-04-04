<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiDocs;

class DocsCommand implements PowerCommand
{
    public function name(): string
    {
        return ':docs';
    }

    public function aliases(): array
    {
        return [':doc'];
    }

    public function description(): string
    {
        return 'Audit and refresh documentation against current codebase';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiDocs::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? "\n\nFocus: {$args}" : '';

        return <<<PROMPT
            DOCS MODE — DOCUMENTATION AUDIT & REFRESH.{$scope}

            Audit documentation against the current codebase. Code and tests are the source of truth — docs must match reality.

            ## Step 1: Inventory
            - Find all documentation files: `docs/`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `*.md` in root
            - Read each documentation file
            - Build a map: what each doc page covers

            ## Step 2: Audit
            For each documentation page, cross-reference against the codebase:

            **Check for staleness:**
            - Do documented file paths still exist?
            - Do documented class/function names match the code?
            - Do documented CLI commands/flags match the actual implementation?
            - Do documented configuration options exist in the config schema?

            **Check for completeness:**
            - Are there major features/components with no documentation?
            - Are there new files/directories not mentioned in architecture docs?
            - Are there CLI commands missing from the help docs?

            **Check for accuracy:**
            - Do code examples actually work?
            - Do documented workflows match the actual execution flow?
            - Are version numbers and dependency lists current?

            ## Step 3: Prioritize
            Rank issues by reader impact:
            1. **Breaking**: Instructions that would fail if followed (wrong commands, missing files)
            2. **Misleading**: Incorrect descriptions of how things work
            3. **Missing**: Important features with no documentation
            4. **Stale**: Outdated but not actively harmful info
            5. **Clarity**: Could be better written but isn't wrong

            ## Step 4: Refresh
            Fix issues in priority order:
            - Update stale references to match current code
            - Add documentation for undocumented features
            - Remove documentation for removed features
            - Keep the existing writing style and structure

            ## Step 5: Report
            ```
            ═══ DOCUMENTATION AUDIT ═══
            Files audited: N
            Issues found: N breaking, N misleading, N missing, N stale
            Files updated: <list>
            Files created: <list>
            Remaining issues: <if any>
            ```

            ## Rules:
            - Code is always right, docs must conform to code
            - Don't rewrite docs that are accurate — only fix what's wrong
            - Preserve existing formatting and style conventions
            - Don't add documentation for internal/private implementation details
            PROMPT;
    }
}
