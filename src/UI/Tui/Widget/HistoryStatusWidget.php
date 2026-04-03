<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Thin status bar shown when the user is browsing conversation history.
 * Displays scroll hints or a "new activity below" indicator.
 */
final class HistoryStatusWidget extends AbstractWidget
{
    private bool $visible = false;

    /** Whether new agent activity occurred while browsing history. */
    private bool $hasHiddenActivity = false;

    /** Make the status bar visible, optionally flagging new activity below. */
    public function show(bool $hasHiddenActivity): void
    {
        $this->visible = true;
        $this->hasHiddenActivity = $hasHiddenActivity;
        $this->invalidate();
    }

    /** Hide the status bar when returning to live view. */
    public function hide(): void
    {
        // Skip repaint if already hidden with no pending activity flag
        if (! $this->visible && ! $this->hasHiddenActivity) {
            return;
        }

        $this->visible = false;
        $this->hasHiddenActivity = false;
        $this->invalidate();
    }

    /**
     * @param  RenderContext  $context  Terminal dimensions
     * @return list<string>  Single ANSI-formatted status line, or empty when hidden
     */
    public function render(RenderContext $context): array
    {
        if (! $this->visible) {
            return [];
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::accent();
        $border = Theme::borderTask();

        $left = "{$dim}Browsing history{$r}";
        // Show activity nudge or scroll keybindings on the right side
        $right = $this->hasHiddenActivity
            ? "{$accent}new activity below ↓{$r}"
            : "{$dim}PgUp/PgDn scroll  End latest{$r}";

        $columns = $context->getColumns();
        // -6 accounts for " │ " on each side
        $spacing = max(1, $columns - AnsiUtils::visibleWidth($left) - AnsiUtils::visibleWidth($right) - 6);
        $line = " {$border}│{$r} {$left}".str_repeat(' ', $spacing)."{$right} {$border}│{$r}";

        return [AnsiUtils::truncateToWidth($line, $columns)];
    }
}
