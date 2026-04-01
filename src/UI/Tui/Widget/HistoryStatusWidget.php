<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class HistoryStatusWidget extends AbstractWidget
{
    private bool $visible = false;

    private bool $hasHiddenActivity = false;

    public function show(bool $hasHiddenActivity): void
    {
        $this->visible = true;
        $this->hasHiddenActivity = $hasHiddenActivity;
        $this->invalidate();
    }

    public function hide(): void
    {
        if (! $this->visible && ! $this->hasHiddenActivity) {
            return;
        }

        $this->visible = false;
        $this->hasHiddenActivity = false;
        $this->invalidate();
    }

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
