<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Kosmokrator\Agent\SubagentStats;

/**
 * Subagent swarm display and orchestration feedback.
 *
 * Covers subagent spawning indicators, running spinners, batch results,
 * tree views, and the swarm dashboard. Extended by RendererInterface.
 */
interface SubagentRendererInterface
{
    /**
     * Update the subagent status display with current stats for all agents.
     *
     * @param  array<string, SubagentStats>  $stats
     */
    public function showSubagentStatus(array $stats): void;

    /**
     * Clear the subagent status display.
     */
    public function clearSubagentStatus(): void;

    /**
     * Show a running indicator while subagents execute.
     * Called AFTER showSubagentSpawn(), cleared implicitly by showSubagentBatch().
     *
     * @param  array<int, array{args: array, id: string}>  $entries
     */
    public function showSubagentRunning(array $entries): void;

    /**
     * Show a grouped batch of subagent spawns (header + running indicators).
     * Called BEFORE agents execute so the user sees them immediately.
     *
     * @param  array<int, array{args: array, id: string}>  $entries
     */
    public function showSubagentSpawn(array $entries): void;

    /**
     * Show grouped batch of subagent results (replaces the spawn display).
     * Called AFTER agents complete with their results.
     *
     * @param  array<int, array{args: array, result: string, success: bool, children?: array}>  $entries
     */
    public function showSubagentBatch(array $entries): void;

    /**
     * Update the live subagent tree display with current orchestrator state.
     *
     * @param  array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array}>  $tree
     */
    public function refreshSubagentTree(array $tree): void;

    /**
     * Set a callback that returns the live agent tree (called during breathing animation).
     */
    public function setAgentTreeProvider(?\Closure $provider): void;

    /**
     * Show the swarm progress dashboard.
     *
     * @param  array  $summary  Aggregated stats (counts, tokens, cost, ETA, etc.)
     * @param  array<string, SubagentStats>  $allStats  All agent stats
     * @param  \Closure|null  $refresh  Callback returning ['summary' => ..., 'stats' => ...] for auto-refresh (TUI)
     */
    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void;
}
