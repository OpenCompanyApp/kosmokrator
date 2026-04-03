<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Kosmokrator\Agent\SubagentOrchestrator;

/**
 * Builds hierarchical tree arrays from SubagentOrchestrator stats.
 *
 * Extracted from AgentLoop — this is a pure display concern that transforms
 * flat stats into nested tree structures for rendering by TUI or ANSI.
 */
final class AgentTreeBuilder
{
    /**
     * Build a provisional tree directly from subagent tool-call entries.
     *
     * This is used before the orchestrator has had time to populate live stats,
     * so the UI can still show the spawned agent tree immediately.
     *
     * @param  array<int, array{args: array, id?: string}>  $entries
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array, toolCalls: int}>
     */
    public static function buildSpawnTree(array $entries): array
    {
        $nodes = [];

        foreach ($entries as $index => $entry) {
            $args = $entry['args'] ?? [];
            $id = (string) ($args['id'] ?? $entry['id'] ?? ('agent-'.($index + 1)));

            $nodes[] = [
                'id' => $id,
                'type' => (string) ($args['type'] ?? 'explore'),
                'task' => (string) ($args['task'] ?? ''),
                'status' => 'queued',
                'elapsed' => 0.0,
                'toolCalls' => 0,
                'success' => false,
                'error' => null,
                'children' => [],
            ];
        }

        return $nodes;
    }

    /**
     * Build the full live agent tree from root.
     *
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array}>
     */
    public static function buildTree(SubagentOrchestrator $orchestrator): array
    {
        return self::buildSubtree($orchestrator, 'root');
    }

    /**
     * Build a subtree rooted at the given parent ID.
     *
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array}>
     */
    public static function buildSubtree(SubagentOrchestrator $orchestrator, string $parentId): array
    {
        $children = [];
        foreach ($orchestrator->allStats() as $stats) {
            if ($stats->parentId === $parentId) {
                $children[] = [
                    'id' => $stats->id,
                    'type' => $stats->agentType,
                    'task' => $stats->task,
                    'status' => $stats->status,
                    'elapsed' => round($stats->elapsed(), 1),
                    'toolCalls' => $stats->toolCalls,
                    'success' => $stats->status === 'done',
                    'error' => $stats->error,
                    'children' => self::buildSubtree($orchestrator, $stats->id),
                ];
            }
        }

        return $children;
    }
}
