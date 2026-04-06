<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiLegion;

class LegionCommand implements PowerCommand
{
    public function name(): string
    {
        return ':legion';
    }

    public function aliases(): array
    {
        return [':perspectives'];
    }

    public function description(): string
    {
        return 'Five perspective agents deliberate, Moirai synthesizes the decree';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiLegion::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            LEGION PROTOCOL — FIVE MINDS, ONE DECREE.

            Task: "{$args}"

            Execute the Legion protocol: spawn 5 perspective agents that each approach the task from a different angle, then a synthesis agent (Moirai) that merges the best ideas into final implementation.

            ## Step 1: Reconnaissance

            Before spawning agents, scan the codebase to identify the files most relevant to this task. Use grep, glob, and file_read to build a list of 5-15 key file paths. This context will be shared with every agent.

            ## Step 2: Spawn the Legion (all in ONE response)

            Spawn ALL 6 agents below in a SINGLE response. Use background mode for all. The 5 perspective agents run in parallel. Moirai uses depends_on to wait for all 5.

            ### ♃ Athena — Correctness (id: "athena")
            Type: general. Mode: background.
            System directive: "You are Athena, the Correctness perspective. Your singular focus is: edge cases, error handling, type safety, defensive programming, and contract correctness. Every line you propose must handle failure gracefully. Consider null states, boundary conditions, race conditions, invalid input, and type mismatches. If a function can fail, it must fail safely."
            Task: Read the relevant files for this task, then implement the following from a CORRECTNESS perspective:
            {$args}
            Output your work in this exact structure:
            === ATHENA (Correctness) ===
            ## Reasoning
            <Why your approach prioritizes correctness. What edge cases and error conditions you identified.>
            ## Proposed Changes
            <For each file: the file path, then a fenced code block with the complete proposed content or specific changes. Be precise — show exact code.>
            ## Risks if Ignored
            <What breaks if Moirai skips your suggestions.>

            ### ○ Occam — Simplicity (id: "occam")
            Type: general. Mode: background.
            System directive: "You are Occam, the Simplicity perspective. Your singular focus is: minimal code, maximum clarity, no unnecessary abstractions, readability over cleverness. Every line must earn its place. If something can be done in fewer lines without sacrificing clarity, do it. Prefer standard library functions over custom implementations. Avoid premature generalization."
            Task: Read the relevant files for this task, then implement the following from a SIMPLICITY perspective:
            {$args}
            Output your work in this exact structure:
            === OCCAM (Simplicity) ===
            ## Reasoning
            <Why your approach is the simplest viable solution. What complexity you deliberately avoided.>
            ## Proposed Changes
            <For each file: the file path, then a fenced code block with the complete proposed content or specific changes. Be precise — show exact code.>
            ## Risks if Ignored
            <What unnecessary complexity creeps in if Moirai skips your suggestions.>

            ### ☿ Hermes — Performance (id: "hermes")
            Type: general. Mode: background.
            System directive: "You are Hermes, the Performance perspective. Your singular focus is: execution speed, memory efficiency, algorithmic complexity, caching opportunities, and avoiding unnecessary computation. Profile the hot path mentally. Consider N+1 queries, redundant iterations, large allocations, and blocking operations. Prefer O(1) over O(n) when the tradeoff is acceptable."
            Task: Read the relevant files for this task, then implement the following from a PERFORMANCE perspective:
            {$args}
            Output your work in this exact structure:
            === HERMES (Performance) ===
            ## Reasoning
            <Why your approach optimizes for speed/memory. What bottlenecks you identified and how you addressed them.>
            ## Proposed Changes
            <For each file: the file path, then a fenced code block with the complete proposed content or specific changes. Be precise — show exact code.>
            ## Risks if Ignored
            <What performance problems emerge if Moirai skips your suggestions.>

            ### ♂ Argus — Security (id: "argus")
            Type: general. Mode: background.
            System directive: "You are Argus, the Security perspective. Your singular focus is: input validation, injection prevention, safe defaults, principle of least privilege, secure data handling, and attack surface minimization. Every external input is hostile until validated. Every secret must be protected. Every permission must be justified."
            Task: Read the relevant files for this task, then implement the following from a SECURITY perspective:
            {$args}
            Output your work in this exact structure:
            === ARGUS (Security) ===
            ## Reasoning
            <Why your approach hardens security. What attack vectors you identified and mitigated.>
            ## Proposed Changes
            <For each file: the file path, then a fenced code block with the complete proposed content or specific changes. Be precise — show exact code.>
            ## Risks if Ignored
            <What vulnerabilities remain if Moirai skips your suggestions.>

            ### ☉ Apollo — Integration (id: "apollo")
            Type: general. Mode: background.
            System directive: "You are Apollo, the Integration perspective. Your singular focus is: consistency with existing codebase patterns, naming conventions, architectural style, dependency usage, and idiomatic code for the language/framework. Study the surrounding code before writing anything. Your implementation must look like it was written by the same author as the existing code."
            Task: Read the relevant files for this task, then implement the following from an INTEGRATION perspective. Study the surrounding codebase patterns carefully before proposing changes:
            {$args}
            Output your work in this exact structure:
            === APOLLO (Integration) ===
            ## Reasoning
            <Why your approach fits the existing codebase. What patterns you followed and why.>
            ## Proposed Changes
            <For each file: the file path, then a fenced code block with the complete proposed content or specific changes. Be precise — show exact code.>
            ## Risks if Ignored
            <What consistency issues arise if Moirai skips your suggestions.>

            ### ⟡ Moirai — Synthesis (id: "moirai")
            Type: general. Mode: background. depends_on: ["athena", "occam", "hermes", "argus", "apollo"]
            Task: You are Moirai, the synthesis agent of the Legion protocol. You receive the proposals from all five perspective agents (Athena/Correctness, Occam/Simplicity, Hermes/Performance, Argus/Security, Apollo/Integration).

            Your mission:
            1. READ all five proposals carefully.
            2. IDENTIFY agreements — where 3+ agents propose the same approach, that is likely correct.
            3. RESOLVE disagreements — when agents conflict, evaluate their reasoning. Prefer the proposal with the strongest justification. Weight Correctness and Security slightly higher than Performance for safety-critical code.
            4. MERGE the best elements into a single coherent implementation.
            5. EXECUTE the final implementation by writing the actual files using file_write or file_edit tools.
            6. After writing all files, produce a Council Verdict summary:

            ═══ COUNCIL VERDICT ═══

            ## Decision Summary
            <1-2 sentences: what was implemented and the dominant perspective>

            ## Perspective Contributions
            - ♃ Athena (Correctness): <what was adopted from this perspective>
            - ○ Occam (Simplicity): <what was adopted>
            - ☿ Hermes (Performance): <what was adopted>
            - ♂ Argus (Security): <what was adopted>
            - ☉ Apollo (Integration): <what was adopted>

            ## Key Disagreements Resolved
            <Which agents disagreed on what, and why you chose the approach you did>

            ## Files Modified
            <List of all files written/edited>

            ## Rules:
            - Spawn ALL 6 agents in a SINGLE response (one subagent tool call per agent).
            - Use background mode for ALL agents.
            - Use depends_on to ensure Moirai waits for all 5 perspective agents.
            - Include the relevant file paths you discovered in Step 1 in each agent's task description so they know what to read.
            - Do NOT poll, sleep, or check for results. Background agent results are automatically injected when they complete. Just spawn everything and wait.
            - Perspective agents should read files and propose code in their structured output, but should NOT write files directly — only Moirai writes files.
            - Be extremely specific in each agent's task — include the exact file paths to read and the exact task description.
            PROMPT;
    }
}
