<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;

/**
 * Reactive compacting loader.
 *
 * Reads hasCompactingLoaderSignal to show/hide. Updates message from
 * thinkingPhraseSignal and compactingStartTimeSignal for elapsed time.
 */
final class CompactingLoaderWidget extends ReactiveWidget
{
    private ?CancellableLoaderWidget $loader = null;

    private bool $mounted = false;

    private string $lastPhrase = '';

    private readonly TuiStateStore $state;

    private static array $phrases = [
        '⧫ Condensing the cosmic record...',
        '⧫ Distilling the essence of memory...',
        '⧫ Weaving threads of context...',
        '⧫ Forging a compact chronicle...',
    ];

    public function __construct(TuiStateStore $state)
    {
        $this->state = $state;
        $this->setId('compacting-loader');
    }

    public function syncFromSignals(): bool
    {
        $shouldShow = $this->state->getHasCompactingLoader();

        if ($shouldShow && ! $this->mounted) {
            $this->mount();

            return true;
        }

        if (! $shouldShow && $this->mounted) {
            $this->unmount();

            return true;
        }

        if (! $this->mounted) {
            return false;
        }

        // Update message with elapsed time
        $phrase = $this->state->getThinkingPhrase() ?? '';
        if ($phrase === $this->lastPhrase) {
            return false;
        }

        $this->lastPhrase = $phrase;
        $this->updateMessage();

        return false;
    }

    public function render(RenderContext $context): array
    {
        if ($this->loader === null) {
            return [];
        }

        return $this->loader->render($context);
    }

    private function mount(): void
    {
        $this->unmount();

        $phrase = self::$phrases[array_rand(self::$phrases)];
        $this->state->setThinkingPhrase($phrase);

        $this->loader = new CancellableLoaderWidget($phrase);
        $this->loader->setId('compacting-loader');
        $this->loader->addStyleClass('compacting');
        $this->loader->setSpinner('hourglass');
        $this->loader->setIntervalMs(120);
        $this->loader->start();

        $this->state->setCompactingStartTime(microtime(true));
        $this->state->setCompactingBreathTick(0);
        $this->lastPhrase = $phrase;
        $this->mounted = true;

        $this->updateMessage();
    }

    private function unmount(): void
    {
        if ($this->loader !== null) {
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->loader = null;
        }

        $this->mounted = false;
        $this->lastPhrase = '';
    }

    private function updateMessage(): void
    {
        if ($this->loader === null) {
            return;
        }

        $r = "\033[0m";
        $dim = "\033[38;5;245m";
        $tick = $this->state->getCompactingBreathTick();
        $t = sin($tick * 0.07);
        $cr = (int) (208 + 40 * $t);
        $cg = (int) (48 + 16 * $t);
        $cb = (int) (48 + 16 * $t);
        $color = Theme::rgb($cr, $cg, $cb);

        $elapsed = (int) (microtime(true) - $this->state->getCompactingStartTime());
        $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);

        $phrase = $this->lastPhrase;
        $this->loader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
    }
}
