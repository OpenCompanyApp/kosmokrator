<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Full-screen dashboard overlay for swarm (multi-agent) mode.
 * Displays aggregate progress, resource usage, active/failed agents, and per-type breakdowns.
 * Auto-refreshes and dismisses on Esc/q.
 */
class SwarmDashboardWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var callable|null Callback invoked when the user dismisses the dashboard. */
    private $onDismissCallback = null;

    /**
     * @param  array<string, mixed>  $summary  Aggregate swarm stats (done, running, failed, tokens, etc.)
     * @param  array<string, mixed>  $allStats  Per-agent detailed statistics
     */
    public function __construct(
        private array $summary,
        private array $allStats,
    ) {}

    /** Update the dashboard data (called on each refresh cycle). */
    public function setData(array $summary, array $allStats): void
    {
        $this->summary = $summary;
        $this->allStats = $allStats;
        $this->invalidate();
    }

    /** Register the callback invoked when the user dismisses the dashboard. */
    public function onDismiss(callable $callback): static
    {
        $this->onDismissCallback = $callback;

        return $this;
    }

    /** Handle Esc/q to dismiss the dashboard. */
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'cancel') || $data === 'q' || $data === "\x01") {
            if ($this->onDismissCallback !== null) {
                ($this->onDismissCallback)();
            }
        }
    }

    /**
     * Render the bordered dashboard with progress bar, status counts, resources, active agents, failures, and type breakdown.
     *
     * @param  RenderContext  $context  Terminal dimensions
     * @return list<string>  ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        $s = $this->summary;

        $r = "\033[0m";
        $gold = "\033[38;2;255;200;80m";
        $green = "\033[38;2;80;220;100m";
        $red = "\033[38;2;255;80;60m";
        $cyan = "\033[38;2;100;200;255m";
        $white = "\033[1;37m";
        $dim = "\033[38;5;240m";
        $text = "\033[38;2;180;180;190m";
        $border = "\033[38;2;160;30;30m";

        $columns = $context->getColumns();
        $w = min($columns - 4, 70);
        $hr = str_repeat('─', $w);

        $pad = function (string $content) use ($r, $border, $w): string {
            return $this->padToWidth($content, $w, $r, $border);
        };
        $blank = "{$border}│{$r}".str_repeat(' ', $w + 2)."{$border}│{$r}";

        $lines = [];

        $lines[] = "{$border}┌{$hr}┐{$r}";
        $lines[] = $blank;
        $lines[] = $pad("{$gold}⏺  S W A R M   C O N T R O L{$r}");
        $lines[] = $blank;

        // Progress bar
        $pct = $s['total'] > 0 ? $s['done'] / $s['total'] : 0;
        $barWidth = 38;
        $filled = (int) round($pct * $barWidth);
        $empty = $barWidth - $filled;
        $barColor = $pct < 0.5 ? $green : $gold;
        $pctStr = number_format($pct * 100, 1).'%';
        $lines[] = $pad("{$barColor}".str_repeat('█', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$white}{$pctStr}{$r}");
        $lines[] = $pad("{$text}{$s['done']} of {$s['total']} agents completed{$r}");
        $lines[] = $blank;

        // Status counts
        $d = (string) $s['done'];
        $ru = (string) $s['running'];
        $q = (string) $s['queued'];
        $f = (string) $s['failed'];
        $lines[] = $pad("{$green}✓ {$d} done{$r}   {$gold}● {$ru} running{$r}   {$cyan}◌ {$q} queued{$r}   {$red}✗ {$f} failed{$r}");
        $lines[] = $blank;

        // Section helper
        $sectionHdr = function (string $icon, string $label) use ($border, $gold, $r, $w): string {
            $labelLen = mb_strlen($icon) + 1 + strlen($label);
            $fill = max(0, $w - $labelLen - 6);

            return "{$border}├──── {$gold}{$icon} {$label}{$r} ".str_repeat("{$border}─", $fill)."{$border}┤{$r}";
        };

        // Resources
        $lines[] = $sectionHdr('☉', 'Resources');
        $lines[] = $blank;

        $tokIn = Theme::formatTokenCount($s['tokensIn']);
        $tokOut = Theme::formatTokenCount($s['tokensOut']);
        $tokTotal = Theme::formatTokenCount($s['tokensIn'] + $s['tokensOut']);
        $lines[] = $pad("{$dim}Tokens    {$white}{$tokIn} in{$dim}  ·  {$white}{$tokOut} out{$dim}  ·  {$white}{$tokTotal} total{$r}");

        $cost = Theme::formatCost($s['cost']);
        $avgCost = Theme::formatCost($s['avgCost']);
        $lines[] = $pad("{$dim}Cost      {$white}{$cost}{$dim}   ·  avg {$white}{$avgCost}{$dim}/agent{$r}");

        $elapsed = AgentDisplayFormatter::formatElapsed($s['elapsed']);
        $rate = $s['rate'] > 0 ? number_format($s['rate'], 1).' agents/min' : 'N/A';
        $lines[] = $pad("{$dim}Elapsed   {$white}{$elapsed}{$dim}  ·  rate {$white}{$rate}{$r}");

        if ($s['eta'] > 0) {
            $eta = '~'.AgentDisplayFormatter::formatElapsed($s['eta']).' remaining';
            $lines[] = $pad("{$dim}ETA       {$gold}{$eta}{$r}");
        }
        $lines[] = $blank;

        // Active
        if ($s['running'] > 0 || $s['retrying'] > 0) {
            $ac = $s['running'] + $s['retrying'];
            $lines[] = $sectionHdr('●', "Active ({$ac})");
            $lines[] = $blank;

            $shown = 0;
            foreach ($s['active'] as $agent) {
                if ($shown >= 8) {
                    $msg = '… '.(count($s['active']) - 8).' more running';
                    $lines[] = $pad("{$dim}{$msg}{$r}");
                    break;
                }
                $icon = $agent->status === 'retrying' ? '↻' : '●';
                $type = str_pad(ucfirst($agent->agentType), 8);
                $task = str_pad(mb_substr($agent->task, 0, 20), 20);
                $bw = 14;
                $ratio = min($agent->elapsed() / 120.0, 1.0);
                $bf = (int) round($ratio * $bw);
                $be = $bw - $bf;
                $el = str_pad((int) $agent->elapsed().'s', 5);
                $tools = $agent->toolCalls.' tools';
                $lines[] = $pad("{$gold}{$icon}{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$gold}".str_repeat('━', $bf)."{$dim}".str_repeat('░', $be)."{$r}  {$dim}{$el} {$tools}{$r}");
                $shown++;
            }
            $lines[] = $blank;
        }

        // Failures
        if ($s['failed'] > 0) {
            $lines[] = $sectionHdr('✗', "Failures ({$s['failed']})");
            $lines[] = $blank;

            if ($s['retriedAndRecovered'] > 0) {
                $perm = $s['failed'] - $s['retriedAndRecovered'];
                $lines[] = $pad("{$dim}{$s['retriedAndRecovered']} recovered via retry  ·  {$perm} permanent{$r}");
            }

            $shown = 0;
            foreach ($s['failures'] as $agent) {
                if ($shown >= 5) {
                    $msg = '… '.(count($s['failures']) - 5).' more';
                    $lines[] = $pad("{$dim}{$msg}{$r}");
                    break;
                }
                $error = mb_substr($agent->error ?? 'unknown', 0, 28);
                $type = str_pad(ucfirst($agent->agentType), 8);
                $task = str_pad(mb_substr($agent->task, 0, 20), 20);
                $lines[] = $pad("{$red}✗{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$dim}{$error}{$r}");
                $shown++;
            }
            $lines[] = $blank;
        }

        // By Type
        if (count($s['byType']) > 1) {
            $lines[] = $sectionHdr('◈', 'By Type');
            $lines[] = $blank;

            $totalTokens = $s['tokensIn'] + $s['tokensOut'];
            foreach ($s['byType'] as $type => $t) {
                $tn = str_pad(ucfirst((string) $type), 10);
                $tc = ($s['cost'] > 0 && $totalTokens > 0)
                    ? Theme::formatCost($s['cost'] * ($t['tokensIn'] + $t['tokensOut']) / $totalTokens)
                    : Theme::formatCost(0.0);
                $lines[] = $pad("{$cyan}{$tn}{$r} {$green}{$t['done']} done{$r}  ·  {$gold}{$t['running']} running{$r}  ·  {$dim}{$t['queued']} queued{$r}  ·  {$white}{$tc}{$r}");
            }
            $lines[] = $blank;
        }

        // Footer
        $lines[] = $pad("{$dim}Esc/q close  ·  auto-refreshes every 2s{$r}");
        $lines[] = "{$border}└{$hr}┘{$r}";

        // Truncate each line to terminal width
        return array_map(
            fn (string $line) => AnsiUtils::truncateToWidth($line, $columns),
            $lines,
        );
    }

    /** Pad content with spaces and border pipes to a fixed visible width. */
    private function padToWidth(string $content, int $width, string $reset, string $border): string
    {
        $visible = AnsiUtils::visibleWidth($content);
        $gap = max(0, $width - $visible);

        return "{$border}│{$reset} {$content}".str_repeat(' ', $gap)." {$border}│{$reset}";
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }
}
