<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * Reactive compacting loader.
 *
 * Reads hasCompactingLoaderSignal to show/hide. Updates message from
 * thinkingPhraseSignal and compactingStartTimeSignal for elapsed time.
 */
final class CompactingLoaderWidget extends ReactiveWidget implements ParentInterface
{
    private ?CancellableLoaderWidget $loader = null;

    private bool $mounted = false;

    private string $lastPhrase = '';

    private int $lastBreathTick = -1;

    private int $lastElapsed = -1;

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

        $phrase = $this->state->getThinkingPhrase() ?? '';
        $tick = $this->state->getCompactingBreathTick();
        $elapsed = (int) (microtime(true) - $this->state->getCompactingStartTime());

        if ($phrase === $this->lastPhrase && $tick === $this->lastBreathTick && $elapsed === $this->lastElapsed) {
            return false;
        }

        $this->lastPhrase = $phrase;
        $this->lastBreathTick = $tick;
        $this->lastElapsed = $elapsed;
        $this->updateMessage($phrase, $tick, $elapsed);

        return true;
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
        $this->attachLoader();

        $this->state->setCompactingStartTime(microtime(true));
        $this->state->setCompactingBreathTick(0);
        $this->mounted = true;

        $elapsed = (int) (microtime(true) - $this->state->getCompactingStartTime());
        $this->lastPhrase = $phrase;
        $this->lastBreathTick = 0;
        $this->lastElapsed = $elapsed;

        $this->updateMessage($phrase, 0, $elapsed);
    }

    private function unmount(): void
    {
        if ($this->loader !== null) {
            $this->loader->detach();
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->loader = null;
        }

        $this->mounted = false;
        $this->lastPhrase = '';
        $this->lastBreathTick = -1;
        $this->lastElapsed = -1;
    }

    private function updateMessage(string $phrase, int $tick, int $elapsed): void
    {
        if ($this->loader === null) {
            return;
        }

        $r = "\033[0m";
        $dim = "\033[38;5;245m";
        $t = sin($tick * 0.07);
        $cr = (int) (208 + 40 * $t);
        $cg = (int) (48 + 16 * $t);
        $cb = (int) (48 + 16 * $t);
        $color = Theme::rgb($cr, $cg, $cb);

        $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);

        $this->loader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
    }

    /**
     * @return list<CancellableLoaderWidget>
     */
    public function all(): array
    {
        return $this->loader !== null ? [$this->loader] : [];
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->attachLoader();
    }

    protected function onDetach(): void
    {
        if ($this->loader !== null && $this->loader->getContext() !== null) {
            $this->loader->detach();
        }
    }

    private function attachLoader(): void
    {
        if ($this->loader === null || $this->loader->getContext() !== null) {
            return;
        }

        $context = $this->getContext();
        if ($context === null) {
            return;
        }

        $this->loader->attach($this, $context);
    }
}
