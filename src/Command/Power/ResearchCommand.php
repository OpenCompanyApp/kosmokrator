<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiResearch;

class ResearchCommand implements PowerCommand
{
    public function name(): string
    {
        return ':research';
    }

    public function aliases(): array
    {
        return [':sci'];
    }

    public function description(): string
    {
        return 'Parallel research agents for comprehensive investigation';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiResearch::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            RESEARCH MODE — PARALLEL SCIENTIFIC INVESTIGATION.

            Research question: "{$args}"

            ## Protocol:

            ### Step 1: Decompose
            Break the research question into 3-7 independent facets that can be investigated in parallel. Each facet should be:
            - Self-contained (can be answered independently)
            - Non-overlapping (minimal duplication between facets)
            - Specific (not "look at everything", but "investigate X pattern in Y context")

            ### Step 2: Launch Scientist Agents
            Spawn one background agent per facet. Each agent should:
            - Search the codebase thoroughly (grep, glob, file reads)
            - Check git history for relevant changes
            - Analyze patterns, conventions, and data flows
            - Return structured findings:
              ```
              ## Facet: <name>
              ### Key Findings
              - Finding 1 (file:line — evidence)
              - Finding 2 (file:line — evidence)
              ### Confidence: high/medium/low
              ### Open Questions: ...
              ```

            ### Step 3: Cross-Validate
            After all agents complete:
            - Check for contradictions between facet findings
            - Verify high-impact claims by reading the cited files yourself
            - Note where multiple facets converge on the same conclusion (strong signal)
            - Flag findings that only one facet supports and confidence is low

            ### Step 4: Synthesize Report
            Produce a structured research report:

            ```
            ═══ RESEARCH REPORT ═══
            Question: <original question>
            Facets investigated: N
            Confidence: high/medium/low

            ## Executive Summary
            <2-3 sentence answer to the research question>

            ## Key Findings
            1. <finding> — supported by facets A, C (high confidence)
            2. <finding> — supported by facet B (medium confidence)

            ## Evidence Map
            <for each finding, list the specific files and lines that support it>

            ## Open Questions
            <things that couldn't be determined from the codebase>

            ## Recommendations
            <actionable next steps based on findings>
            ```

            Rules:
            - Every claim must cite specific files and line numbers
            - Distinguish between "confirmed by code" and "inferred from patterns"
            - If the codebase doesn't contain enough information to answer fully, say so
            - Prefer depth in fewer facets over shallow coverage of many
            PROMPT;
    }
}
