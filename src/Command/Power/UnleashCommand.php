<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiUnleash;

class UnleashCommand implements PowerCommand
{
    public function name(): string
    {
        return ':unleash';
    }

    public function aliases(): array
    {
        return [':swarm', ':nuke'];
    }

    public function description(): string
    {
        return 'Unleash a massive swarm of agents on a task';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiUnleash::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            You have been UNLEASHED. The user needs maximum coverage on this task:

            "{$args}"

            Execute a massive parallel swarm attack:

            1. DECOMPOSE the task into 15-25 independent dimensions, angles, or areas that can be investigated or worked on in parallel.

            2. SPAWN Phase 1: one background agent per dimension. Use explore type for research tasks, general type for tasks that need to write files. Give each a descriptive id (e.g. "auth-security", "db-perf", "api-contracts"). In each agent's task description, instruct it to spawn 3-5 sub-agents of its own to go even deeper — specify exactly what each sub-agent should investigate.

            3. SPAWN Phase 2 (depends_on Phase 1): 3-5 synthesis agents that each consume a cluster of related Phase 1 results and produce structured findings. Give them ids like "synthesis-security", "synthesis-architecture", etc.

            4. SPAWN Phase 3 (depends_on Phase 2): one final agent id "compiler" that depends on all synthesis agents and compiles everything into a single comprehensive deliverable document.

            Rules:
            - Spawn ALL Phase 1 agents in a SINGLE response (one tool call per agent, all in parallel).
            - Spawn Phase 2 and Phase 3 in the SAME response as Phase 1 — use depends_on to sequence them. Do NOT wait between phases.
            - Use background mode for ALL agents across ALL phases.
            - Be extremely specific in each agent's task — don't say "investigate X", say exactly what files to look at, what patterns to search for, and what format to return findings in.
            - Phase 1 agents MUST each spawn their own sub-agents for thorough coverage.
            - Do NOT poll, sleep, or check for results. Background agent results are automatically injected into your conversation when they complete. Just spawn everything and wait — the system handles delivery.
            - When all results have been delivered, compile the final output.

            This is the nuclear option. Overwhelm the problem. Leave no stone unturned.
            PROMPT;
    }
}
