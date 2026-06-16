<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Athanor\Computed;
use Athanor\Signal;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;

/**
 * Declarative status bar composition.
 *
 * Replaces StatusBarBuilder. Reads all state from TuiStateStore signals.
 * The status bar message is driven by the statusBarMessage Computed,
 * which auto-updates when modeLabel, permissionLabel, or statusDetail change.
 *
 * The progress bar (context meter) is driven by contextPercentComputed.
 */
final class StatusBar
{
    /**
     * Build the status bar widget tree.
     *
     * @return array{AbstractWidget, ProgressBarWidget} The container and progress bar widgets
     */
    public static function build(TuiStateStore $state): array
    {
        $progressBar = new ProgressBarWidget(200_000, '%message%  %bar%');
        $progressBar->setId('status-bar');
        $progressBar->setBarCharacter('━');
        $progressBar->setEmptyBarCharacter('─');
        $progressBar->setProgressCharacter('━');
        $progressBar->setBarWidth(20);
        $progressBar->setMessage($state->getStatusBarMessage());
        $progressBar->start(200_000, 0);

        return [$progressBar, $progressBar];
    }

    /**
     * Create the ProgressBarWidget for the status bar.
     */
    public static function createProgressBar(TuiStateStore $state): ProgressBarWidget
    {
        $progressBar = new ProgressBarWidget(200_000, '%message%  %bar%');
        $progressBar->setId('status-bar');
        $progressBar->setBarCharacter('━');
        $progressBar->setEmptyBarCharacter('─');
        $progressBar->setProgressCharacter('━');
        $progressBar->setBarWidth(20);
        $progressBar->setMessage($state->getStatusBarMessage());
        $progressBar->start(200_000, 0);

        return $progressBar;
    }

    /**
     * Update the status bar progress and message reactively.
     *
     * Called from the status bar Effect — fires when any status signal changes.
     */
    public static function sync(ProgressBarWidget $bar, TuiStateStore $state): void
    {
        $bar->setMessage($state->getStatusBarMessage());

        $tokensIn = $state->getTokensIn() ?? 0;
        $maxContext = $state->getMaxContext();

        if ($maxContext !== null && $maxContext > 0) {
            if ($bar->getMaxSteps() !== $maxContext) {
                $bar->start($maxContext, $tokensIn);
            } else {
                $bar->setProgress($tokensIn);
            }
        }
    }

    /**
     * Build the formatted statusDetail string and set it on the store.
     */
    public static function formatTokenDetail(TuiStateStore $state, string $model, int $tokensIn, int $maxContext): string
    {
        $inLabel = Theme::formatTokenCount($tokensIn);
        $maxLabel = Theme::formatTokenCount($maxContext);

        $detail = "{$inLabel}/{$maxLabel} · {$model}";
        $state->setStatusDetail($detail);

        return $detail;
    }

    /**
     * Build the formatted runtime detail string and set it on the store.
     */
    public static function formatRuntimeDetail(TuiStateStore $state, string $provider, string $model, int $tokensIn, int $maxContext): string
    {
        $label = $provider.'/'.$model;

        if ($state->getMaxContext() === null) {
            $detail = $label;
        } else {
            $inLabel = Theme::formatTokenCount($tokensIn);
            $maxLabel = Theme::formatTokenCount($maxContext);
            $detail = "{$inLabel}/{$maxLabel} · {$label}";
        }

        $state->setStatusDetail($detail);

        return $detail;
    }
}
