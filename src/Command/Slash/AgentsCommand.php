<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\LLM\ModelCatalog;

/**
 * Displays a live dashboard of subagent (swarm) progress, costs, and token usage.
 */
class AgentsCommand implements SlashCommand
{
    public function name(): string
    {
        return '/agents';
    }

    /** @return string[] Alternative command names */
    public function aliases(): array
    {
        return ['/swarm'];
    }

    /** @return string One-line description for the help listing */
    public function description(): string
    {
        return 'Show swarm progress dashboard';
    }

    /** @return bool Whether this command executes immediately (no agent turn needed) */
    public function immediate(): bool
    {
        return true;
    }

    /**
     * @param  string  $args  Unused command arguments
     * @param  SlashCommandContext  $ctx  Current session context with orchestrator access
     * @return SlashCommandResult Always continues the session
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $orchestrator = $ctx->orchestrator;
        if ($orchestrator === null) {
            $ctx->ui->showNotice('No subagent orchestrator available.');

            return SlashCommandResult::continue();
        }

        $allStats = $orchestrator->allStats();
        if ($allStats === []) {
            $ctx->ui->showNotice('No agents have been spawned yet.');

            return SlashCommandResult::continue();
        }

        $model = $ctx->llm->getModel();
        $summary = self::buildSummary($allStats, $ctx->models, $model);

        $refresh = function () use ($orchestrator, $ctx, $model) {
            $stats = $orchestrator->allStats();

            return [
                'summary' => self::buildSummary($stats, $ctx->models, $model),
                'stats' => $stats,
            ];
        };

        $ctx->ui->showAgentsDashboard($summary, $allStats, $refresh);

        return SlashCommandResult::continue();
    }

    /**
     * Compute aggregated swarm statistics from all agent stats.
     *
     * @param  array<string, SubagentStats>  $stats
     * @return array<string, mixed>
     */
    public static function buildSummary(array $stats, ?ModelCatalog $models, string $model): array
    {
        $done = $running = $queued = $failed = $cancelled = $retrying = 0;
        $totalIn = $totalOut = $totalTools = $totalRetries = 0;
        $retriedAndRecovered = 0;
        $startTime = PHP_FLOAT_MAX;

        $byType = [];
        $active = [];
        $failures = [];

        foreach ($stats as $s) {
            match ($s->status) {
                'done' => $done++,
                'running' => $running++,
                'queued', 'queued_global', 'waiting' => $queued++,
                'failed' => $failed++,
                'cancelled' => $cancelled++,
                'retrying' => $retrying++,
                default => null,
            };

            $totalIn += $s->tokensIn;
            $totalOut += $s->tokensOut;
            $totalTools += $s->toolCalls;
            $totalRetries += $s->retries;

            if ($s->retries > 0 && $s->status === 'done') {
                $retriedAndRecovered++;
            }

            if ($s->startTime > 0 && $s->startTime < $startTime) {
                $startTime = $s->startTime;
            }

            // Per-type breakdown
            $type = $s->agentType ?: 'unknown';
            if (! isset($byType[$type])) {
                $byType[$type] = ['done' => 0, 'running' => 0, 'queued' => 0, 'failed' => 0, 'tokensIn' => 0, 'tokensOut' => 0];
            }
            match ($s->status) {
                'done' => $byType[$type]['done']++,
                'running' => $byType[$type]['running']++,
                'queued', 'queued_global', 'waiting' => $byType[$type]['queued']++,
                'failed' => $byType[$type]['failed']++,
                default => null,
            };
            $byType[$type]['tokensIn'] += $s->tokensIn;
            $byType[$type]['tokensOut'] += $s->tokensOut;

            // Collect active agents
            if (in_array($s->status, ['running', 'retrying'], true)) {
                $active[] = $s;
            }

            // Collect failures
            if ($s->status === 'failed') {
                $failures[] = $s;
            }
        }

        // Sort active by elapsed desc (longest running first)
        usort($active, fn (SubagentStats $a, SubagentStats $b) => $b->elapsed() <=> $a->elapsed());

        // Sort failures by endTime desc (most recent first)
        usort($failures, fn (SubagentStats $a, SubagentStats $b) => $b->endTime <=> $a->endTime);

        $elapsed = $startTime < PHP_FLOAT_MAX ? microtime(true) - $startTime : 0;
        $total = count($stats);
        $rate = $elapsed > 0 && $done > 0 ? $done / ($elapsed / 60) : 0;
        $eta = $rate > 0 ? ($total - $done - $failed - $cancelled) / $rate * 60 : 0;
        $cost = $models?->estimateCost($model, $totalIn, $totalOut) ?? 0.0;
        $avgCost = $done > 0 ? $cost / $done : 0.0;

        return [
            'total' => $total,
            'done' => $done,
            'running' => $running,
            'queued' => $queued,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'retrying' => $retrying,
            'retriedAndRecovered' => $retriedAndRecovered,
            'totalRetries' => $totalRetries,
            'tokensIn' => $totalIn,
            'tokensOut' => $totalOut,
            'totalTools' => $totalTools,
            'cost' => $cost,
            'avgCost' => $avgCost,
            'elapsed' => $elapsed,
            'rate' => $rate,
            'eta' => $eta,
            'active' => $active,
            'failures' => $failures,
            'byType' => $byType,
            'model' => $model,
        ];
    }
}
