<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Kosmokrator\Agent\SubagentStats;

/**
 * Shared formatting utilities for agent display across TUI and ANSI renderers.
 *
 * Stateless injectable service — transforms agent data into formatted ANSI
 * strings used by both renderer implementations.
 */
final class AgentDisplayFormatter
{
    /**
     * Summarize agent types for group headers.
     *
     * @param  array<int, array{args: array}>  $entries
     * @return string E.g. "Explore agents", "2 Explore + 1 General agents"
     */
    public function summarizeAgentTypes(array $entries): string
    {
        $types = [];
        foreach ($entries as $entry) {
            $type = ucfirst((string) ($entry['args']['type'] ?? 'explore'));
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        if (count($types) === 1) {
            $type = array_key_first($types);

            return $type.(count($entries) === 1 ? ' agent' : ' agents');
        }

        $parts = [];
        foreach ($types as $type => $count) {
            $parts[] = "{$count} {$type}";
        }

        return implode(' + ', $parts).' agents';
    }

    /**
     * Extract a short preview from subagent output for tree display.
     *
     * Skips empty lines, markdown headers (#), and horizontal rules (---).
     * Strips leading list markers (- or *). Truncates at 80 characters.
     */
    public function extractResultPreview(string $output): string
    {
        foreach (explode("\n", trim($output)) as $line) {
            $stripped = trim($line);
            if ($stripped === '' || str_starts_with($stripped, '#') || str_starts_with($stripped, '---')) {
                continue;
            }
            $stripped = preg_replace('/^[-*]\s+/', '', $stripped);
            if (mb_strlen($stripped) > 80) {
                return mb_substr($stripped, 0, 80).'...';
            }

            return $stripped;
        }

        return '';
    }

    /**
     * Render a nested child agent tree with box-drawing indentation.
     *
     * Returns a multi-line string with tree connectors (├─, └─, │) showing
     * each child's success/fail icon, type, task, and elapsed time.
     *
     * @param  array<int, array{type: string, task: string, success: bool, elapsed: float, children?: array}>  $children
     */
    public function renderChildTree(array $children, string $indent): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();
        $output = '';

        $last = count($children) - 1;
        foreach ($children as $i => $child) {
            $connector = $i === $last ? '└─' : '├─';
            $continuation = $i === $last ? '   ' : '│  ';
            $icon = $child['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            $type = ucfirst($child['type']);
            $task = mb_strlen($child['task']) > 40 ? mb_substr($child['task'], 0, 40).'…' : $child['task'];
            $elapsed = $child['elapsed'] > 0 ? " {$dim}(".$this->formatElapsed($child['elapsed'])."){$r}" : '';

            $output .= "{$indent}{$connector} {$icon} {$dim}{$type}{$r} {$task}{$elapsed}\n";

            if (($child['children'] ?? []) !== []) {
                $output .= $this->renderChildTree($child['children'], "{$indent}{$continuation}");
            }
        }

        return $output;
    }

    /**
     * Format a compact agent label with type coloring.
     *
     * @param  array{type?: string, id?: string, task?: string}  $args
     * @return array{string, string} [formatted label, type ANSI color code]
     */
    public function formatAgentLabel(array $args): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $type = ucfirst((string) ($args['type'] ?? 'explore'));
        $id = isset($args['id']) && $args['id'] !== '' ? (string) $args['id'] : null;
        $task = (string) ($args['task'] ?? '');
        $taskPreview = mb_strlen($task) > 50 ? mb_substr($task, 0, 50).'...' : $task;

        $typeColor = match (strtolower($type)) {
            'general' => Theme::agentGeneral(),
            'plan' => Theme::agentPlan(),
            default => Theme::agentDefault(),
        };

        $primary = $id !== null
            ? "{$typeColor}{$type}{$r} {$id}"
            : "{$typeColor}{$type}{$r}";

        return ["{$primary} {$dim}· {$taskPreview}{$r}", $typeColor];
    }

    /**
     * Format an elapsed duration in human-readable form.
     *
     * @return string E.g. "42s", "1m 30s", "1h 5m"
     */
    public function formatElapsed(float $seconds): string
    {
        $s = (int) $seconds;
        if ($s < 60) {
            return $s.'s';
        }
        if ($s < 3600) {
            return (int) ($s / 60).'m '.($s % 60).'s';
        }

        return (int) ($s / 3600).'h '.(int) (($s % 3600) / 60).'m';
    }

    /**
     * Format stats for a completed agent entry (elapsed time, tool count).
     *
     * @param  array{stats?: ?SubagentStats}  $entry  Batch entry with optional stats
     */
    public function formatAgentStats(array $entry): string
    {
        $stats = $entry['stats'] ?? null;
        if (! $stats instanceof SubagentStats) {
            return '';
        }

        $dim = Theme::dim();
        $r = Theme::reset();
        $elapsed = $this->formatElapsed($stats->elapsed());
        $tools = $stats->toolCalls.' tool'.($stats->toolCalls !== 1 ? 's' : '');

        return " {$dim}· {$elapsed} · {$tools}{$r}";
    }

    /**
     * Format coordination tags (depends_on, group) for spawn display.
     *
     * @return string E.g. " → depends on: id1, id2 · group: writers"
     */
    public function formatCoordinationTags(array $args): string
    {
        $dim = Theme::dim();
        $r = Theme::reset();
        $parts = [];
        $dependsOn = $args['depends_on'] ?? [];
        $group = isset($args['group']) && $args['group'] !== '' ? (string) $args['group'] : null;

        if (is_array($dependsOn) && $dependsOn !== []) {
            $parts[] = 'depends on: '.implode(', ', $dependsOn);
        }
        if ($group !== null) {
            $parts[] = "group: {$group}";
        }

        if ($parts === []) {
            return '';
        }

        return " {$dim}→ ".implode(' · ', $parts)."{$r}";
    }

    /**
     * Count total nodes in a tree recursively (including nested children).
     *
     * @param  array<int, array{children?: array}>  $nodes
     */
    public function countNodes(array $nodes): int
    {
        $count = count($nodes);
        foreach ($nodes as $node) {
            $count += $this->countNodes($node['children'] ?? []);
        }

        return $count;
    }

    /**
     * Count nodes matching a given status recursively.
     *
     * @param  array<int, array{status: string, children?: array}>  $nodes
     */
    public function countByStatus(array $nodes, string $status): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if ($node['status'] === $status) {
                $count++;
            }
            $count += $this->countByStatus($node['children'] ?? [], $status);
        }

        return $count;
    }
}
