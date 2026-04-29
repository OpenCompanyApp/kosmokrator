<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Agent\SubagentStats;

/**
 * Builds hierarchical tree arrays from SubagentOrchestrator stats.
 *
 * Extracted from AgentLoop — this is a pure display concern that transforms
 * flat stats into nested tree structures for rendering by TUI or ANSI.
 */
final class AgentTreeBuilder
{
    public const DEFAULT_SIBLING_LIMIT = 40;

    /**
     * Build a provisional tree directly from subagent tool-call entries.
     *
     * This is used before the orchestrator has had time to populate live stats,
     * so the UI can still show the spawned agent tree immediately.
     *
     * @param  array<int, array{args: array, id?: string}>  $entries
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array, toolCalls: int}>
     */
    public function buildSpawnTree(array $entries): array
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
    public function buildTree(SubagentOrchestrator $orchestrator, ?int $siblingLimit = self::DEFAULT_SIBLING_LIMIT): array
    {
        return $this->buildSubtree($orchestrator, 'root', $siblingLimit);
    }

    /**
     * Build a subtree rooted at the given parent ID.
     *
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array}>
     */
    public function buildSubtree(SubagentOrchestrator $orchestrator, string $parentId, ?int $siblingLimit = self::DEFAULT_SIBLING_LIMIT): array
    {
        $index = $this->buildChildrenIndex($orchestrator->allStats());

        return $this->buildIndexedSubtree($index, $parentId, $siblingLimit);
    }

    /**
     * @param  array<string, SubagentStats>  $stats
     * @return array<string, list<SubagentStats>>
     */
    private function buildChildrenIndex(array $stats): array
    {
        $index = [];

        foreach ($stats as $agentStats) {
            $index[$agentStats->parentId ?? 'root'][] = $agentStats;
        }

        foreach ($index as &$children) {
            $this->sortStats($children);
        }
        unset($children);

        return $index;
    }

    /**
     * @param  array<string, list<SubagentStats>>  $index
     * @return array<int, array<string, mixed>>
     */
    private function buildIndexedSubtree(array $index, string $parentId, ?int $siblingLimit): array
    {
        $stats = $index[$parentId] ?? [];
        if ($stats === []) {
            return [];
        }

        $visible = $siblingLimit === null || $siblingLimit <= 0
            ? $stats
            : array_slice($stats, 0, $siblingLimit);

        $nodes = [];
        foreach ($visible as $agentStats) {
            $nodes[] = $this->buildNode($agentStats, $index, $siblingLimit);
        }

        if ($siblingLimit !== null && $siblingLimit > 0 && count($stats) > $siblingLimit) {
            $nodes[] = $this->buildSummaryNode(array_slice($stats, $siblingLimit), $index, $parentId);
        }

        return $nodes;
    }

    /**
     * @param  array<string, list<SubagentStats>>  $index
     * @return array<string, mixed>
     */
    private function buildNode(SubagentStats $stats, array $index, ?int $siblingLimit): array
    {
        return [
            'id' => $stats->id,
            'type' => $stats->agentType,
            'task' => $stats->task,
            'status' => $stats->status,
            'elapsed' => round($stats->elapsed(), 1),
            'toolCalls' => $stats->toolCalls,
            'success' => $stats->status === 'done',
            'error' => $stats->error,
            'queueReason' => $stats->queueReason,
            'lastTool' => $stats->lastTool,
            'lastMessagePreview' => $stats->lastMessagePreview,
            'nextRetryAt' => $stats->nextRetryAt,
            'children' => $this->buildIndexedSubtree($index, $stats->id, $siblingLimit),
        ];
    }

    /**
     * @param  list<SubagentStats>  $hidden
     * @param  array<string, list<SubagentStats>>  $index
     * @return array<string, mixed>
     */
    private function buildSummaryNode(array $hidden, array $index, string $parentId): array
    {
        $summary = $this->summarizeHiddenStats($hidden, $index);

        return [
            'id' => "summary-{$parentId}",
            'type' => 'summary',
            'task' => $this->formatSummaryTask($summary['count'], $summary['statuses']),
            'status' => 'summary',
            'elapsed' => 0.0,
            'toolCalls' => 0,
            'success' => true,
            'error' => null,
            'hiddenCount' => $summary['count'],
            'hiddenStatuses' => $summary['statuses'],
            'children' => [],
        ];
    }

    /**
     * @param  list<SubagentStats>  $stats
     * @param  array<string, list<SubagentStats>>  $index
     * @return array{count: int, statuses: array<string, int>}
     */
    private function summarizeHiddenStats(array $stats, array $index): array
    {
        $count = 0;
        $statuses = [];

        foreach ($stats as $agentStats) {
            $count++;
            $statuses[$agentStats->status] = ($statuses[$agentStats->status] ?? 0) + 1;

            $childSummary = $this->summarizeHiddenStats($index[$agentStats->id] ?? [], $index);
            $count += $childSummary['count'];
            foreach ($childSummary['statuses'] as $status => $statusCount) {
                $statuses[$status] = ($statuses[$status] ?? 0) + $statusCount;
            }
        }

        return ['count' => $count, 'statuses' => $statuses];
    }

    /**
     * @param  array<string, int>  $statuses
     */
    private function formatSummaryTask(int $count, array $statuses): string
    {
        $parts = [];
        foreach (['retrying', 'running', 'failed', 'waiting', 'queued_global', 'queued', 'done', 'cancelled'] as $status) {
            if (($statuses[$status] ?? 0) > 0) {
                $label = $status === 'queued_global' ? 'queued' : $status;
                $parts[] = $statuses[$status].' '.$label;
            }
        }

        $suffix = $parts === [] ? '' : ' ('.implode(', ', array_slice($parts, 0, 3)).')';

        return "{$count} more agent".($count === 1 ? '' : 's').$suffix;
    }

    /**
     * @param  list<SubagentStats>  $stats
     */
    private function sortStats(array &$stats): void
    {
        usort($stats, function (SubagentStats $a, SubagentStats $b): int {
            $priority = $this->statusPriority($a->status) <=> $this->statusPriority($b->status);
            if ($priority !== 0) {
                return $priority;
            }

            $recency = $this->activityTimestamp($b) <=> $this->activityTimestamp($a);
            if ($recency !== 0) {
                return $recency;
            }

            return $a->id <=> $b->id;
        });
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'retrying' => 0,
            'running' => 1,
            'failed' => 2,
            'cancelled' => 3,
            'waiting' => 4,
            'queued_global' => 5,
            'queued' => 6,
            'done' => 7,
            default => 8,
        };
    }

    private function activityTimestamp(SubagentStats $stats): float
    {
        return max($stats->lastActivityTime, $stats->endTime, $stats->startTime);
    }
}
