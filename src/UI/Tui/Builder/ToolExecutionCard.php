<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Builder;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Manages the tool executing animation: a CancellableLoaderWidget with
 * its own 20fps timer for color-modulated spinning.
 *
 * Lifecycle: start() → timer runs at 20fps → stop() cancels timer and removes widget.
 * Filtering (which tools get this card) stays in the caller (TuiToolRenderer).
 */
final class ToolExecutionCard
{
    private ?CancellableLoaderWidget $loader = null;

    private ?string $timerId = null;

    public function __construct(
        private readonly TuiStateStore $state,
        private readonly ContainerWidget $conversation,
        private readonly \Closure $addConversationWidget,
    ) {}

    /**
     * Create and start the tool executing loader with its own 20fps timer.
     */
    public function start(): void
    {
        $this->stop();

        $r = Theme::reset();
        $dim = Theme::dim();
        $blue = Theme::rgb(112, 160, 208);

        $this->loader = new CancellableLoaderWidget("{$blue}running...{$r}");
        $this->loader->setId('tool-executing');
        $this->loader->addStyleClass('tool-result');
        $this->loader->setSpinner('cosmos', 120);

        $this->state->setToolExecutingStartTime(microtime(true));
        $this->state->setToolExecutingBreathTick(0);

        ($this->addConversationWidget)($this->loader);

        $this->timerId = EventLoop::repeat(0.05, function () use ($dim, $r): void {
            if ($this->loader === null) {
                return;
            }
            $this->state->tickToolExecutingBreath();
            $tick = $this->state->getToolExecutingBreathTick();
            $t = sin($tick * 0.07);
            $cr = (int) (112 + 40 * $t);
            $cg = (int) (160 + 40 * $t);
            $cb = (int) (208 + 47 * $t);
            $color = Theme::rgb($cr, $cg, $cb);

            $elapsed = (int) (microtime(true) - $this->state->getToolExecutingStartTime());
            $time = $elapsed > 0 ? " {$dim}({$elapsed}s){$r}" : '';

            $preview = $this->state->getToolExecutingPreview() ?? 'running...';
            $this->loader->setMessage("{$color}{$preview}{$r}{$time}");
        });
    }

    /**
     * Update the preview text shown in the loader.
     */
    public function updatePreview(string $output): void
    {
        $lines = explode("\n", trim($output));
        $last = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                $last = $trimmed;
                break;
            }
        }
        if ($last !== '') {
            $this->state->setToolExecutingPreview(mb_strlen($last) > 100 ? mb_substr($last, 0, 100).'…' : $last);
        }
    }

    /**
     * Stop the timer and remove the loader widget.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
        if ($this->loader !== null) {
            $this->loader->setFinishedIndicator('');
            $this->loader->stop();
            $this->conversation->remove($this->loader);
            $this->loader = null;
        }
        $this->state->setToolExecutingPreview(null);
    }

    public function isActive(): bool
    {
        return $this->loader !== null;
    }
}
