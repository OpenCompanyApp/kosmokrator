<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiTrace;

class TraceCommand implements PowerCommand
{
    public function name(): string
    {
        return ':trace';
    }

    public function aliases(): array
    {
        return [':debug'];
    }

    public function description(): string
    {
        return 'Evidence-driven deep trace analysis of a bug or issue';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiTrace::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            DEEP TRACE MODE ACTIVATED. Investigate this issue with forensic precision:

            "{$args}"

            Execute the following investigative protocol:

            1. HYPOTHESIZE: Generate 5-8 competing hypotheses for the root cause. Be specific — name files, functions, data flows, race conditions, configuration issues.

            2. RANK: Order hypotheses by prior probability based on:
               - Code patterns you can observe (search for them)
               - Error message semantics
               - Common failure modes for this type of system
               - Recent changes (check git log)

            3. DISCRIMINATE: For each hypothesis, design a targeted probe — a specific search, file read, or test that would confirm or eliminate it. Run the most discriminating probes first (ones that split the hypothesis space most effectively).

            4. NARROW: After each probe, update your confidence scores. Eliminate disproven hypotheses. If a hypothesis gains strong evidence, focus remaining probes on confirming it and understanding the mechanism.

            5. DIAGNOSE: When you've identified the root cause with high confidence (>80%), produce a structured diagnosis:
               - **Root cause**: One sentence
               - **Evidence**: Bullet list of findings that confirm it
               - **Mechanism**: How exactly the bug manifests (trace the execution path)
               - **Fix**: Specific code changes needed
               - **Verification**: How to confirm the fix works

            Rules:
            - Show your work — display hypothesis rankings and confidence updates after each probe.
            - Never guess. Every claim must be backed by evidence you found in the code.
            - If multiple root causes contribute, identify all of them and their interaction.
            - Check git blame and recent commits for relevant changes.
            - Search broadly first, then narrow. Don't tunnel-vision on the first plausible theory.
            PROMPT;
    }
}
