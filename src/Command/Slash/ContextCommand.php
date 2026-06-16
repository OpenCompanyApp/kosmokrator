<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Agent\ContextBreakdown;
use Kosmokrator\Agent\ContextSuggestion;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

final class ContextCommand implements SlashCommand
{
    public function name(): string
    {
        return '/context';
    }

    public function aliases(): array
    {
        return ['/ctx'];
    }

    public function description(): string
    {
        return 'Show context budget and cache diagnostics';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $breakdown = $ctx->agentLoop->contextBreakdown();
        $suggestions = $ctx->agentLoop->contextSuggestions();
        $args = trim($args);

        if (str_contains($args, '--json')) {
            $ctx->ui->showNotice(json_encode($this->json($breakdown, $suggestions), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');

            return SlashCommandResult::continue();
        }

        $ctx->ui->showNotice($this->render($breakdown, $suggestions, str_contains($args, '--full')));

        return SlashCommandResult::continue();
    }

    /**
     * @param  ContextSuggestion[]  $suggestions
     */
    private function render(ContextBreakdown $breakdown, array $suggestions, bool $full): string
    {
        $usedPercent = $breakdown->effectiveWindow > 0
            ? round(($breakdown->estimatedTokens / $breakdown->effectiveWindow) * 100, 1)
            : 0.0;

        $lines = [
            'Context',
            "Model: {$breakdown->model}",
            "Estimated: {$breakdown->estimatedTokens} / {$breakdown->effectiveWindow} tokens ({$usedPercent}%)",
            'Thresholds: warning '.($breakdown->budget['warning_threshold'] ?? '?')
                .', compact '.($breakdown->budget['auto_compact_threshold'] ?? '?')
                .', blocking '.($breakdown->budget['blocking_threshold'] ?? '?'),
        ];

        if (($breakdown->cache['cache_read_tokens'] ?? 0) || ($breakdown->cache['cache_write_tokens'] ?? 0)) {
            $lines[] = 'Last cache: read '.($breakdown->cache['cache_read_tokens'] ?? 0)
                .', write '.($breakdown->cache['cache_write_tokens'] ?? 0);
        }
        if (($breakdown->cache['drop_cause'] ?? '') !== '') {
            $lines[] = 'Cache note: '.$breakdown->cache['drop_cause'];
        }

        $lines[] = '';
        $lines[] = 'Top buckets:';
        foreach ($breakdown->topBuckets($full ? 12 : 6) as $bucket) {
            $lines[] = '- '.$bucket->name.': '.$bucket->tokens.' tokens ('.$bucket->percentOf($breakdown->estimatedTokens).'%)';
        }

        $lines[] = '';
        $lines[] = 'Largest items:';
        foreach (array_slice($breakdown->largestItems, 0, $full ? 10 : 5) as $item) {
            $label = $item->toolName ?? $item->name;
            $where = $item->messageIndex !== null ? ' msg#'.$item->messageIndex : '';
            $lines[] = '- '.$label.$where.': '.$item->tokens.' tokens';
        }

        if ($suggestions !== []) {
            $lines[] = '';
            $lines[] = 'Suggestions:';
            foreach ($suggestions as $suggestion) {
                $lines[] = '- ['.$suggestion->severity.'] '.$suggestion->message.' '.$suggestion->action;
            }
        }

        if ($full) {
            $lines[] = '';
            $lines[] = 'Cache stats:';
            foreach (['file_read', 'web'] as $key) {
                if (! isset($breakdown->cache[$key]) || ! is_array($breakdown->cache[$key])) {
                    continue;
                }
                $stats = $breakdown->cache[$key];
                $lines[] = '- '.$key.': hits '.($stats['hits'] ?? 0)
                    .', misses '.($stats['misses'] ?? 0)
                    .', evictions '.($stats['evictions'] ?? 0)
                    .', entries '.($stats['entries'] ?? 0);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  ContextSuggestion[]  $suggestions
     * @return array<string, mixed>
     */
    private function json(ContextBreakdown $breakdown, array $suggestions): array
    {
        return [
            'model' => $breakdown->model,
            'estimated_tokens' => $breakdown->estimatedTokens,
            'context_window' => $breakdown->contextWindow,
            'effective_window' => $breakdown->effectiveWindow,
            'budget' => $breakdown->budget,
            'buckets' => array_map(static fn ($bucket): array => [
                'name' => $bucket->name,
                'tokens' => $bucket->tokens,
                'source' => $bucket->source,
                'percent' => $bucket->percentOf($breakdown->estimatedTokens),
            ], $breakdown->buckets),
            'largest_items' => array_map(static fn ($item): array => [
                'name' => $item->name,
                'tokens' => $item->tokens,
                'message_index' => $item->messageIndex,
                'tool_name' => $item->toolName,
                'path' => $item->path,
                'preview' => $item->preview,
            ], $breakdown->largestItems),
            'cache' => $breakdown->cache,
            'suggestions' => array_map(static fn ($suggestion): array => [
                'severity' => $suggestion->severity,
                'code' => $suggestion->code,
                'message' => $suggestion->message,
                'action' => $suggestion->action,
            ], $suggestions),
        ];
    }
}
