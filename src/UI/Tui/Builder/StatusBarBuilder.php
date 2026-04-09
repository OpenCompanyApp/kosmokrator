<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Builder;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Widget\ProgressBarWidget;

/**
 * Builds and updates the context/token status bar.
 *
 * Message text is driven reactively by the statusBarMessage Computed
 * (modeLabel + permissionLabel + statusDetail). Progress bar position
 * is updated imperatively via updateProgress().
 */
final class StatusBarBuilder
{
    public function __construct(
        private readonly TuiStateStore $state,
        private readonly ProgressBarWidget $widget,
    ) {}

    public static function create(TuiStateStore $state): self
    {
        $widget = new ProgressBarWidget(200_000, '%message%  %bar%');
        $widget->setId('status-bar');
        $widget->setBarCharacter('━');
        $widget->setEmptyBarCharacter('─');
        $widget->setProgressCharacter('━');
        $widget->setBarWidth(20);
        $widget->setMessage($state->getStatusBarMessage());
        $widget->start(200_000, 0);

        return new self($state, $widget);
    }

    public function getWidget(): ProgressBarWidget
    {
        return $this->widget;
    }

    /**
     * Update the status bar message from the computed statusBarMessage.
     *
     * Called by the status-bar Effect — fires when modeLabel, permissionLabel,
     * or statusDetail signals change.
     */
    public function update(): void
    {
        $this->widget->setMessage($this->state->getStatusBarMessage());
    }

    /**
     * Update the progress bar position for token usage.
     *
     * Called imperatively from showStatus() and refreshRuntimeSelection()
     * because ProgressBarWidget's start/setProgress are not signal-driven.
     */
    public function updateProgress(int $tokensIn, int $maxContext): void
    {
        if ($this->widget->getMaxSteps() !== $maxContext) {
            $this->widget->start($maxContext, $tokensIn);
        } else {
            $this->widget->setProgress($tokensIn);
        }
    }

    /**
     * Build the statusDetail string for token display and set it on the store.
     *
     * @return string The formatted statusDetail (also set on state)
     */
    public function formatTokenDetail(string $model, int $tokensIn, int $maxContext): string
    {
        $inLabel = Theme::formatTokenCount($tokensIn);
        $maxLabel = Theme::formatTokenCount($maxContext);
        $ratio = min(1.0, $tokensIn / max(1, $maxContext));
        $r = Theme::reset();
        $sep = Theme::dim()."·{$r}";
        $dimWhite = Theme::dimWhite();
        $ctxColor = Theme::contextColor($ratio);

        $detail = "{$ctxColor}{$inLabel}/{$maxLabel}{$r} {$sep} {$dimWhite}{$model}{$r}";
        $this->state->setStatusDetail($detail);

        return $detail;
    }

    /**
     * Build the statusDetail string for provider/model display and set it on the store.
     */
    public function formatRuntimeDetail(string $provider, string $model, int $tokensIn, int $maxContext): string
    {
        $label = $provider.'/'.$model;
        $r = Theme::reset();
        $dimWhite = Theme::dimWhite();

        if ($this->state->getMaxContext() === null) {
            $detail = "{$dimWhite}{$label}{$r}";
        } else {
            $inLabel = Theme::formatTokenCount($tokensIn);
            $maxLabel = Theme::formatTokenCount($maxContext);
            $ratio = min(1.0, $tokensIn / max(1, $maxContext));
            $sep = Theme::dim()."·{$r}";
            $ctxColor = Theme::contextColor($ratio);
            $detail = "{$ctxColor}{$inLabel}/{$maxLabel}{$r} {$sep} {$dimWhite}{$label}{$r}";
        }

        $this->state->setStatusDetail($detail);

        return $detail;
    }
}
