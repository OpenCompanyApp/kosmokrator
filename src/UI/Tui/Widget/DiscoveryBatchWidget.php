<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * @phpstan-type DiscoveryItem array{
 *   name: string,
 *   label: string,
 *   detail: string,
 *   summary: string,
 *   status: 'pending'|'success'|'error'
 * }
 */

/**
 * Displays a batch of file-read / glob / grep / bash / memory-search operations performed
 * during the discovery phase ("reading the omens"). Shown while the agent is gathering context
 * before acting on a task.
 */
class DiscoveryBatchWidget extends AbstractWidget implements ToggleableWidgetInterface
{
    private bool $expanded = false;

    /** @var list<array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}> */
    private array $items = [];

    /**
     * @param  list<array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}>  $items
     */
    public function __construct(array $items = [])
    {
        $this->setItems($items);
    }

    /** Toggle between collapsed summary and expanded detail view. */
    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
        $this->invalidate();
    }

    /** Explicitly set the expanded/collapsed state. */
    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->invalidate();
    }

    /** Check whether the widget is currently expanded. */
    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    /**
     * @param  list<array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}>  $items
     */
    public function setItems(array $items): void
    {
        $this->items = array_map(function (array $item): array {
            $item['detail'] = str_replace("\t", '   ', $item['detail']);

            return $item;
        }, $items);

        $this->invalidate();
    }

    /**
     * Render the discovery batch: a header line, tool summary, and per-item labels or details.
     */
    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $gold = Theme::accent();
        $dim = Theme::dim();
        $text = Theme::text();
        $cols = $context->getColumns();

        $lines = [
            "{$gold}".Theme::toolIcon('file_read').' Reading the omens'."{$r}",
            " │ {$dim}{$this->formatSummary()}{$r}",
        ];

        if (! $this->expanded) {
            foreach ($this->items as $item) {
                $lines[] = " │ {$text}{$item['label']}{$r}";
            }

            $lines[] = " └ {$dim}⊛ Details (ctrl+o to reveal){$r}";

            return $this->truncateLines($lines, $cols);
        }

        foreach ($this->items as $index => $item) {
            $lines[] = ' │';
            $lines[] = $this->formatExpandedHeader($item);

            if ($item['status'] === 'pending') {
                $lines[] = " │   {$dim}awaiting result...{$r}";
            } else {
                foreach (explode("\n", $item['detail']) as $detailLine) {
                    $lines[] = " │   {$detailLine}";
                }
            }
        }

        $lines[] = ' │';
        $lines[] = " └ {$dim}⊛ Details (ctrl+o to collapse){$r}";

        return $this->truncateLines($lines, $cols);
    }

    /**
     * Build a human-readable summary counting each tool type in the batch.
     */
    private function formatSummary(): string
    {
        $counts = [
            'file_read' => 0,
            'glob' => 0,
            'grep' => 0,
            'bash' => 0,
            'memory_search' => 0,
        ];

        foreach ($this->items as $item) {
            if (isset($counts[$item['name']])) {
                $counts[$item['name']]++;
            }
        }

        $parts = [];
        if ($counts['file_read'] > 0) {
            $parts[] = $counts['file_read'].' '.($counts['file_read'] === 1 ? 'read' : 'reads');
        }
        if ($counts['glob'] > 0) {
            $parts[] = $counts['glob'].' '.($counts['glob'] === 1 ? 'glob' : 'globs');
        }
        if ($counts['grep'] > 0) {
            $parts[] = $counts['grep'].' '.($counts['grep'] === 1 ? 'search' : 'searches');
        }
        if ($counts['bash'] > 0) {
            $parts[] = $counts['bash'].' '.($counts['bash'] === 1 ? 'probe' : 'probes');
        }
        if ($counts['memory_search'] > 0) {
            $parts[] = $counts['memory_search'].' '.($counts['memory_search'] === 1 ? 'recall' : 'recalls');
        }

        return $parts === [] ? 'No omens yet' : implode('  ·  ', $parts);
    }

    /**
     * Render a single expanded item header with status icon, friendly tool name, label, and optional meta.
     *
     * @param  array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}  $item
     */
    private function formatExpandedHeader(array $item): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $status = match ($item['status']) {
            'success' => Theme::success().'✓',
            'error' => Theme::error().'✗',
            default => Theme::info().'●',
        };
        $friendly = match ($item['name']) {
            'file_read' => 'Read',
            'glob' => 'Scan',
            'grep' => 'Search',
            'bash' => 'Probe',
            'memory_search' => 'Recall',
            default => ucfirst($item['name']),
        };
        $meta = $item['summary'] !== '' ? "{$dim}  ·  {$item['summary']}{$r}" : '';

        return " │ {$status}{$r} {$friendly}  {$item['label']}{$meta}";
    }

    /**
     * Truncate every rendered line that exceeds the terminal column width.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private function truncateLines(array $lines, int $cols): array
    {
        foreach ($lines as $index => $line) {
            if (AnsiUtils::visibleWidth($line) > $cols) {
                $lines[$index] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
        }

        return $lines;
    }
}
