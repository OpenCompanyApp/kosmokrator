<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Input\HelpGenerator;
use Kosmokrator\UI\Tui\Input\KeybindingRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Floating help overlay showing all TUI keybindings.
 *
 * Displayed when the user presses '?' and hidden on any subsequent key.
 * Renders as a bordered box centered in the terminal with categorized bindings.
 */
final class HelpOverlayWidget extends AbstractWidget
{
    private const BINDINGS = [
        'Navigation' => [
            ['Enter', 'Send message'],
            ['Shift+Enter', 'New line'],
            ['↑ / ↓', 'Input history'],
            ['PgUp / PgDn', 'Scroll conversation'],
            ['End', 'Jump to live output'],
            ['Ctrl+R', 'Reverse search history'],
            ['Esc', 'Cancel / close'],
        ],
        'Mode' => [
            ['Shift+Tab', 'Cycle mode (edit → plan → ask)'],
            ['/edit', 'Edit mode'],
            ['/plan', 'Plan mode'],
            ['/ask', 'Ask mode'],
        ],
        'Tools' => [
            ['Ctrl+O', 'Toggle tool results'],
            ['Ctrl+C', 'Cancel running request'],
            ['Ctrl+L', 'Force refresh'],
        ],
        'Commands' => [
            ['/compact', 'Compact context'],
            ['/new', 'New session'],
            ['/clear', 'Clear screen'],
            ['/quit', 'Exit KosmoKrator'],
            ['/settings', 'Open settings'],
            ['?', 'Show this help'],
        ],
    ];

    private ?KeybindingRegistry $registry;

    public function __construct(?KeybindingRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::accent();
        $border = Theme::borderAccent();
        $text = Theme::text();
        $bold = Theme::bold();
        $cols = $context->getColumns();

        $lines = [];

        // Title
        $title = "{$accent}{$bold} KosmoKrator Keybindings{$r}";
        $lines[] = $title;
        $lines[] = '';

        if ($this->registry !== null) {
            $this->renderDynamicBindings($lines, $r, $dim, $accent, $text, $bold);
        } else {
            $this->renderFallbackBindings($lines, $r, $dim, $accent, $text, $bold);
        }

        $lines[] = "{$dim}Press ? or Esc to close{$r}";

        // Wrap in a bordered box
        $contentWidth = 0;
        foreach ($lines as $line) {
            $w = AnsiUtils::visibleWidth($line);
            if ($w > $contentWidth) {
                $contentWidth = $w;
            }
        }
        $contentWidth = min($contentWidth + 4, $cols - 4);

        $result = [];
        $result[] = "{$border}┌".str_repeat('─', $contentWidth)."┐{$r}";
        foreach ($lines as $line) {
            $visW = AnsiUtils::visibleWidth($line);
            $pad = max(0, $contentWidth - $visW);
            $result[] = "{$border}│{$r} {$line}".str_repeat(' ', $pad)." {$border}│{$r}";
        }
        $result[] = "{$border}└".str_repeat('─', $contentWidth)."┘{$r}";

        return $result;
    }

    /**
     * Render bindings from the KeybindingRegistry via HelpGenerator.
     *
     * @param string[] $lines
     */
    private function renderDynamicBindings(array &$lines, string $r, string $dim, string $accent, string $text, string $bold): void
    {
        $generator = new HelpGenerator();
        $overlayData = $generator->helpOverlay('normal', $this->registry);

        // Group rows by group name
        $grouped = [];
        foreach ($overlayData as $row) {
            $group = $row['group'] !== '' ? $row['group'] : 'Other';
            $grouped[$group][] = $row;
        }

        foreach ($grouped as $group => $rows) {
            $lines[] = "{$accent}{$bold}{$group}{$r}";
            foreach ($rows as $row) {
                $padded = str_pad($row['key'], 18);
                $lines[] = "  {$dim}{$padded}{$r}  {$text}{$row['description']}{$r}";
            }
            $lines[] = '';
        }
    }

    /**
     * Render the hardcoded fallback BINDINGS constant.
     *
     * @param string[] $lines
     */
    private function renderFallbackBindings(array &$lines, string $r, string $dim, string $accent, string $text, string $bold): void
    {
        foreach (self::BINDINGS as $category => $bindings) {
            $lines[] = "{$accent}{$bold}{$category}{$r}";
            foreach ($bindings as [$key, $desc]) {
                $padded = str_pad($key, 18);
                $lines[] = "  {$dim}{$padded}{$r}  {$text}{$desc}{$r}";
            }
            $lines[] = '';
        }
    }
}
