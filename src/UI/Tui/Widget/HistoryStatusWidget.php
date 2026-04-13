<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Reactive status bar shown when the user is browsing conversation history.
 *
 * Reads scrollOffset and hasHiddenActivityBelow signals via beforeRender().
 * No manual show()/hide() calls needed — visibility is derived from state.
 */
final class HistoryStatusWidget extends ReactiveWidget
{
    private bool $visible = false;

    private bool $hasHiddenActivity = false;

    private readonly TuiStateStore $state;

    public function __construct(TuiStateStore $state)
    {
        $this->state = $state;
    }

    public static function of(TuiStateStore $state): self
    {
        return new self($state);
    }

    public function syncFromSignals(): bool
    {
        $scrollOffset = $this->state->getScrollOffset();

        if ($scrollOffset <= 0) {
            if (! $this->visible && ! $this->hasHiddenActivity) {
                return false;
            }

            $this->visible = false;
            $this->hasHiddenActivity = false;

            return true;
        }

        $newHasHidden = $this->state->getHasHiddenActivityBelow();

        if ($this->visible && $newHasHidden === $this->hasHiddenActivity) {
            return false;
        }

        $this->visible = true;
        $this->hasHiddenActivity = $newHasHidden;

        return true;
    }

    /**
     * @return list<string> Single ANSI-formatted status line, or empty when hidden
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
        $right = $this->hasHiddenActivity
            ? "{$accent}new activity below ↓{$r}"
            : "{$dim}PgUp/PgDn scroll  End latest{$r}";

        $columns = $context->getColumns();
        $spacing = max(1, $columns - AnsiUtils::visibleWidth($left) - AnsiUtils::visibleWidth($right) - 6);
        $line = " {$border}│{$r} {$left}".str_repeat(' ', $spacing)."{$right} {$border}│{$r}";

        return [AnsiUtils::truncateToWidth($line, $columns)];
    }
}
