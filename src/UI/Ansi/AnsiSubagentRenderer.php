<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\SubagentRendererInterface;
use Kosmokrator\UI\Theme;

/**
 * ANSI fallback implementation of subagent swarm display.
 *
 * Handles subagent status trees, spawn/batch display, and the swarm dashboard.
 */
final class AnsiSubagentRenderer implements SubagentRendererInterface
{
    public function __construct(
        private readonly AgentDisplayFormatter $formatter = new AgentDisplayFormatter,
    ) {}

    public function showSubagentStatus(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $r = "\033[0m";
        $dim = "\033[38;5;243m";
        $green = "\033[38;2;80;200;120m";
        $gold = "\033[38;2;218;165;32m";
        $red = "\033[38;2;255;100;100m";
        $blue = "\033[38;2;100;149;237m";
        $border = "\033[38;5;240m";

        $running = count(array_filter($stats, fn ($s) => $s->status === 'running'));
        $done = count(array_filter($stats, fn ($s) => $s->status === 'done'));
        $total = count($stats);

        echo "\n{$border}  ┌ {$gold}{$running} running, {$done}/{$total} finished{$r}\n";

        $items = array_values($stats);
        $last = count($items) - 1;

        foreach ($items as $i => $s) {
            $connector = $i === $last ? '└─' : '├─';
            $task = mb_substr($s->task, 0, 50);

            $statusIcon = match ($s->status) {
                'done' => "{$green}✓{$r}",
                'running' => "{$gold}●{$r}",
                'failed' => "{$red}✗{$r}",
                'waiting' => "{$blue}◌{$r}",
                'retrying' => "{$gold}↻{$r}",
                default => "{$dim}○{$r}",
            };

            $meta = ucfirst($s->agentType)." \"{$task}\"";

            $detail = match ($s->status) {
                'done' => " · {$s->toolCalls} tools · ".Theme::formatTokenCount($s->tokensIn + $s->tokensOut).' tokens',
                'running' => " · {$s->toolCalls} tools · running",
                'waiting' => ' · waiting on '.implode(', ', $s->dependsOn),
                'queued' => $s->group !== null ? " · queued (group: {$s->group})" : ' · queued',
                'queued_global' => ' · queued (concurrency limit)',
                'retrying' => " · retry #{$s->retries} · {$s->toolCalls} tools",
                'failed' => ' · failed: '.mb_substr($s->error ?? '', 0, 40),
                default => '',
            };

            echo "{$border}  {$connector} {$statusIcon} {$dim}{$meta}{$detail}{$r}\n";
        }
    }

    /** No-op: ANSI mode prints status inline, nothing to clear. */
    public function clearSubagentStatus(): void
    {
        // ANSI mode: status is printed inline, nothing to clear
    }

    public function showSubagentRunning(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $border = Theme::borderTask();
        $count = count($entries);
        $label = $count === 1 ? 'Running...' : "{$count} agents running...";

        echo "{$border}  {$dim}⎿ {$label}{$r}\n";
    }

    public function showSubagentSpawn(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $cyan = "\033[38;2;100;200;220m";
        $border = Theme::borderTask();

        $count = count($entries);
        $types = $this->formatter->summarizeAgentTypes($entries);
        $isBg = ($entries[0]['args']['mode'] ?? 'await') === 'background';
        $bgTag = $isBg ? " {$dim}(background){$r}" : '';

        // Single agent: compact one-liner
        if ($count === 1) {
            $e = $entries[0];
            [$label, $typeColor] = $this->formatter->formatAgentLabel($e['args']);
            echo "\n{$border}  {$cyan}⏺{$r} {$label}{$bgTag}\n";

            return;
        }

        // Multiple agents: tree
        echo "\n{$border}  {$cyan}⏺ {$count} agents{$r}{$bgTag}\n";

        $last = $count - 1;
        foreach ($entries as $i => $entry) {
            $connector = $i === $last ? '└─' : '├─';
            [$label, $typeColor] = $this->formatter->formatAgentLabel($entry['args']);
            $coord = $this->formatter->formatCoordinationTags($entry['args']);

            echo "{$border}  {$connector} {$typeColor}●{$r} {$label}{$coord}\n";
        }
    }

    public function showSubagentBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();
        $cyan = "\033[38;2;100;200;220m";
        $border = Theme::borderTask();

        // Filter out background acks — show remaining (failures, awaited results)
        $entries = array_values(array_filter($entries, fn ($e) => ! str_contains($e['result'], 'spawned in background')));
        if (empty($entries)) {
            return;
        }

        $count = count($entries);
        $succeeded = count(array_filter($entries, fn ($e) => $e['success']));
        $failed = $count - $succeeded;
        $types = $this->formatter->summarizeAgentTypes($entries);

        // Single agent: compact
        if ($count === 1) {
            $e = $entries[0];
            $icon = $e['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            [$label, $_] = $this->formatter->formatAgentLabel($e['args']);
            $stats = $this->formatter->formatAgentStats($e);
            $preview = $this->formatter->extractResultPreview($e['result']);
            $children = $e['children'] ?? [];

            echo "\n{$border}  {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo $this->formatter->renderChildTree($children, "{$border}     ");
            }
            if ($preview !== '') {
                echo "{$border}     {$dim}⎿ {$preview}{$r}\n";
            }

            return;
        }

        // Multiple agents: tree
        $failSuffix = $failed > 0 ? " {$red}({$failed} failed){$r}" : '';
        echo "\n{$border}  {$green}✓{$r} {$succeeded}/{$count} {$types} finished{$failSuffix}\n";

        $last = $count - 1;
        foreach ($entries as $i => $entry) {
            $connector = $i === $last ? '└─' : '├─';
            $continuation = $i === $last ? '  ' : '│ ';
            $icon = $entry['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            [$label, $_] = $this->formatter->formatAgentLabel($entry['args']);
            $stats = $this->formatter->formatAgentStats($entry);
            $preview = $this->formatter->extractResultPreview($entry['result']);
            $children = $entry['children'] ?? [];

            echo "{$border}  {$connector} {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo $this->formatter->renderChildTree($children, "{$border}  {$continuation}  ");
            }
            if ($preview !== '') {
                echo "{$border}  {$continuation}  {$dim}⎿ {$preview}{$r}\n";
            }
        }
    }

    /** No-op in ANSI mode. */
    public function refreshSubagentTree(array $tree): void {}

    /** No-op in ANSI mode. */
    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        echo $this->formatDashboard($summary, $allStats);
    }

    private function formatDashboard(array $s, array $allStats): string
    {
        $r = Theme::reset();
        $border = Theme::primaryDim();
        $gold = Theme::accent();
        $green = Theme::success();
        $red = Theme::error();
        $cyan = Theme::info();
        $white = Theme::white();
        $dim = Theme::dim();
        $text = Theme::text();
        $w = 72;

        $out = '';
        $hr = str_repeat('─', $w);
        $strip = fn (string $s): string => preg_replace('/\033\[[^m]*m/', '', $s);
        $pad = function (string $content) use ($r, $border, $w, $strip): string {
            $visible = mb_strlen($strip($content));
            $gap = max(0, $w - $visible);

            return "{$border}  │{$r} {$content}".str_repeat(' ', $gap)." {$border}│{$r}\n";
        };
        $blank = "{$border}  │{$r}".str_repeat(' ', $w + 2)."{$border}│{$r}\n";

        // Top border
        $out .= "\n{$border}  ┌{$hr}┐{$r}\n";
        $out .= $blank;

        // Title
        $out .= $pad("{$gold}⏺  S W A R M   C O N T R O L{$r}");
        $out .= $blank;

        // Progress bar
        $pct = $s['total'] > 0 ? $s['done'] / $s['total'] : 0;
        $barWidth = 40;
        $filled = (int) round($pct * $barWidth);
        $empty = $barWidth - $filled;
        $barColor = $pct < 0.5 ? $green : $gold;
        $pctStr = number_format($pct * 100, 1).'%';
        $out .= $pad("{$barColor}".str_repeat('█', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$white}{$pctStr}{$r}");
        $out .= $pad("{$text}{$s['done']} of {$s['total']} agents completed{$r}");
        $out .= $blank;

        // Status counts
        $d = (string) $s['done'];
        $ru = (string) $s['running'];
        $q = (string) $s['queued'];
        $f = (string) $s['failed'];
        $out .= $pad("{$green}✓ {$d} done{$r}   {$gold}● {$ru} running{$r}   {$cyan}◌ {$q} queued{$r}   {$red}✗ {$f} failed{$r}");
        $out .= $blank;

        // Section header helper
        $sectionHdr = function (string $icon, string $label) use ($border, $gold, $r, $w): string {
            $labelLen = mb_strlen($icon) + 1 + strlen($label);
            $fill = max(0, $w - $labelLen - 6);

            return "{$border}  ├──── {$gold}{$icon} {$label}{$r} ".str_repeat("{$border}─", $fill)."{$border}┤{$r}\n";
        };

        $out .= $sectionHdr('☉', 'Resources');
        $out .= $blank;

        $tokIn = Theme::formatTokenCount($s['tokensIn']);
        $tokOut = Theme::formatTokenCount($s['tokensOut']);
        $tokTotal = Theme::formatTokenCount($s['tokensIn'] + $s['tokensOut']);
        $out .= $pad("{$dim}Tokens    {$white}{$tokIn} in{$dim}  ·  {$white}{$tokOut} out{$dim}  ·  {$white}{$tokTotal} total{$r}");

        $cost = Theme::formatCost($s['cost']);
        $avgCost = Theme::formatCost($s['avgCost']);
        $out .= $pad("{$dim}Cost      {$white}{$cost}{$dim}   ·  avg {$white}{$avgCost}{$dim}/agent{$r}");

        $elapsed = $this->formatter->formatElapsed($s['elapsed']);
        $rate = $s['rate'] > 0 ? number_format($s['rate'], 1).' agents/min' : 'N/A';
        $out .= $pad("{$dim}Elapsed   {$white}{$elapsed}{$dim}  ·  rate {$white}{$rate}{$r}");

        if ($s['eta'] > 0) {
            $eta = '~'.$this->formatter->formatElapsed($s['eta']).' remaining';
            $out .= $pad("{$dim}ETA       {$gold}{$eta}{$r}");
        }
        $out .= $blank;

        // Active
        if ($s['running'] > 0 || $s['retrying'] > 0) {
            $ac = $s['running'] + $s['retrying'];
            $out .= $sectionHdr('●', "Active ({$ac})");
            $out .= $blank;

            $shown = 0;
            foreach ($s['active'] as $agent) {
                if ($shown >= 8) {
                    $remaining = count($s['active']) - 8;
                    $out .= $pad("{$dim}… {$remaining} more agents running{$r}");
                    break;
                }
                $out .= $pad($this->dashFormatAgentLine($agent));
                $shown++;
            }
            $out .= $blank;
        }

        // Failures
        if ($s['failed'] > 0) {
            $out .= $sectionHdr('✗', "Failures ({$s['failed']})");
            $out .= $blank;

            if ($s['retriedAndRecovered'] > 0) {
                $permanent = $s['failed'] - $s['retriedAndRecovered'];
                $out .= $pad("{$dim}{$s['retriedAndRecovered']} recovered via retry  ·  {$permanent} permanent{$r}");
            }

            $shown = 0;
            foreach ($s['failures'] as $agent) {
                if ($shown >= 5) {
                    $remaining = count($s['failures']) - 5;
                    $out .= $pad("{$dim}… {$remaining} more failures{$r}");
                    break;
                }
                $error = mb_substr($agent->error ?? 'unknown', 0, 30);
                $retryTag = $agent->retries > 0 ? "  {$red}exhausted{$r}" : '';
                $type = str_pad(ucfirst($agent->agentType), 8);
                $task = str_pad(mb_substr($agent->task, 0, 22), 22);
                $out .= $pad("{$red}✗{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$dim}{$error}{$r}{$retryTag}");
                $shown++;
            }
            $out .= $blank;
        }

        // By Type
        if (count($s['byType']) > 1) {
            $out .= $sectionHdr('◈', 'By Type');
            $out .= $blank;

            $totalTokens = $s['tokensIn'] + $s['tokensOut'];
            foreach ($s['byType'] as $type => $t) {
                $typeName = str_pad(ucfirst($type), 10);
                $typeCost = ($s['cost'] > 0 && $totalTokens > 0)
                    ? Theme::formatCost($s['cost'] * ($t['tokensIn'] + $t['tokensOut']) / $totalTokens)
                    : Theme::formatCost(0.0);
                $out .= $pad("{$cyan}{$typeName}{$r} {$green}{$t['done']} done{$r}  ·  {$gold}{$t['running']} running{$r}  ·  {$dim}{$t['queued']} queued{$r}  ·  {$white}{$typeCost}{$r}");
            }
            $out .= $blank;
        }

        // Bottom border
        $out .= "{$border}  └{$hr}┘{$r}\n\n";

        return $out;
    }

    private function dashFormatAgentLine(SubagentStats $agent): string
    {
        $r = Theme::reset();
        $gold = Theme::accent();
        $dim = Theme::dim();
        $text = Theme::text();

        $icon = $agent->status === 'retrying' ? '↻' : '●';
        $type = str_pad(ucfirst($agent->agentType), 8);
        $task = str_pad(mb_substr($agent->task, 0, 22), 22);

        $barWidth = 16;
        $ratio = min($agent->elapsed() / 120.0, 1.0);
        $filled = (int) round($ratio * $barWidth);
        $empty = $barWidth - $filled;

        $elapsed = str_pad((int) $agent->elapsed().'s', 5);
        $tools = $agent->toolCalls.' tool'.($agent->toolCalls !== 1 ? 's' : '');
        $retryNote = $agent->status === 'retrying' ? "  retry #{$agent->retries}" : '';

        return "{$gold}{$icon}{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$gold}".str_repeat('━', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$dim}{$elapsed} {$tools}{$retryNote}{$r}";
    }
}
