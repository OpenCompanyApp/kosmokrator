<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

final class ContextSuggestionService
{
    /**
     * @return ContextSuggestion[]
     */
    public function suggest(ContextBreakdown $breakdown): array
    {
        $suggestions = [];
        $effective = max(1, $breakdown->effectiveWindow);
        $usedPercent = ($breakdown->estimatedTokens / $effective) * 100;

        if ($usedPercent >= 95 || ($breakdown->budget['is_at_blocking_limit'] ?? false)) {
            $suggestions[] = new ContextSuggestion('critical', 'context.blocking', 'Context is near the blocking limit.', 'Compact now or clear old tool output.');
        } elseif ($usedPercent >= 80 || ($breakdown->budget['is_above_warning'] ?? false)) {
            $suggestions[] = new ContextSuggestion('warning', 'context.high', 'Context usage is high.', 'Use targeted reads and avoid pasting large outputs.');
        }

        foreach ($breakdown->largestItems as $item) {
            if ($item->tokens > 10_000 || $item->percentOf($breakdown->estimatedTokens) >= 15.0) {
                $name = $item->toolName !== null ? "{$item->toolName} output" : $item->name;
                $suggestions[] = new ContextSuggestion('warning', 'item.large', "{$name} is using {$item->tokens} estimated tokens.", 'Rerun narrower commands or inspect saved truncation output.');
                break;
            }
        }

        foreach ($breakdown->buckets as $bucket) {
            $percent = $bucket->percentOf($breakdown->estimatedTokens);
            if ($bucket->name === 'tool:bash' && $percent >= 20.0) {
                $suggestions[] = new ContextSuggestion('warning', 'tool.bash_bloat', 'Shell output dominates the context.', 'Prefer background commands, targeted grep, or saved output inspection.');
            }
            if ($bucket->name === 'memory' && $percent >= 5.0) {
                $suggestions[] = new ContextSuggestion('info', 'memory.large', 'Memory/session recall is a noticeable part of context.', 'Review saved memories if recall feels noisy.');
            }
            if (in_array($bucket->name, ['task_tree', 'parent_brief'], true) && $bucket->tokens > 2000) {
                $suggestions[] = new ContextSuggestion('info', 'prompt.volatile_large', 'Volatile prompt context is large.', 'Simplify active tasks or parent delegation text.');
            }
        }

        if (($breakdown->cache['drop_cause'] ?? '') !== '') {
            $suggestions[] = new ContextSuggestion('info', 'cache.drop', 'Provider cache read dropped.', 'Likely cause: '.$breakdown->cache['drop_cause']);
        }

        return $this->unique($suggestions);
    }

    /**
     * @param  ContextSuggestion[]  $suggestions
     * @return ContextSuggestion[]
     */
    private function unique(array $suggestions): array
    {
        $seen = [];
        $out = [];
        foreach ($suggestions as $suggestion) {
            if (isset($seen[$suggestion->code])) {
                continue;
            }
            $seen[$suggestion->code] = true;
            $out[] = $suggestion;
        }

        return $out;
    }
}
