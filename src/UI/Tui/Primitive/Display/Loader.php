<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Display;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;

/**
 * Reactive loader widget bound to phrase and color signals.
 *
 * Wraps a CancellableLoaderWidget internally. The parent When primitive
 * calls mount()/unmount() to manage the CancellableLoaderWidget lifecycle
 * (add/remove from parent container).
 *
 * Usage:
 *   Loader::of($state->thinkingPhraseSignal())
 *       ->color($state->breathColorSignal())
 *       ->spinner('cosmos')
 */
final class Loader extends ReactiveWidget
{
    private string $lastPhrase = '';

    private string $lastColor = '';

    private string $spinnerName = 'cosmos';

    private int $intervalMs = 120;

    private readonly Signal $phraseSignal;

    private readonly Signal $colorSignal;

    private ?CancellableLoaderWidget $loader = null;

    private function __construct(Signal $phraseSignal, Signal $colorSignal)
    {
        $this->phraseSignal = $phraseSignal;
        $this->colorSignal = $colorSignal;
    }

    /**
     * Create a reactive loader.
     *
     * @param  Signal<?string>  $phraseSignal  Signal for the loader message
     * @param  Signal<?string>  $colorSignal  Signal for the ANSI color
     */
    public static function of(Signal $phraseSignal, Signal $colorSignal): self
    {
        return new self($phraseSignal, $colorSignal);
    }

    public function spinner(string $name): self
    {
        $this->spinnerName = $name;

        return $this;
    }

    public function intervalMs(int $ms): self
    {
        $this->intervalMs = $ms;

        return $this;
    }

    /**
     * Create and return the underlying CancellableLoaderWidget.
     *
     * Called by the When primitive when the condition becomes true.
     * The returned widget should be added to a container.
     */
    public function mount(): CancellableLoaderWidget
    {
        $this->unmount();

        $phrase = $this->phraseSignal->get() ?? '';
        $color = $this->colorSignal->get() ?? '';

        $this->loader = new CancellableLoaderWidget($phrase);
        $this->loader->setId('reactive-loader');
        $this->loader->setSpinner($this->spinnerName);
        $this->loader->setIntervalMs($this->intervalMs);
        $this->loader->start();

        $this->lastPhrase = $phrase;
        $this->lastColor = $color;

        return $this->loader;
    }

    /**
     * Stop and discard the underlying CancellableLoaderWidget.
     *
     * Called by the When primitive when the condition becomes false.
     */
    public function unmount(): void
    {
        if ($this->loader !== null) {
            $this->loader->setFinishedIndicator('');
            $this->loader->stop();
            $this->loader = null;
        }
    }

    /**
     * Get the mounted loader, if any.
     */
    public function getLoader(): ?CancellableLoaderWidget
    {
        return $this->loader;
    }

    protected function syncFromSignals(): bool
    {
        if ($this->loader === null) {
            return false;
        }

        $newPhrase = $this->phraseSignal->get() ?? '';
        $newColor = $this->colorSignal->get() ?? '';

        if ($newPhrase === $this->lastPhrase && $newColor === $this->lastColor) {
            return false;
        }

        $this->lastPhrase = $newPhrase;
        $this->lastColor = $newColor;

        $r = "\033[0m";
        $this->loader->setMessage("{$newColor}{$newPhrase}{$r}");

        return false; // CancellableLoaderWidget manages its own invalidation
    }

    public function render(RenderContext $context): array
    {
        // Rendering is delegated to the CancellableLoaderWidget which
        // lives in the parent container. This widget is a controller.
        return [];
    }
}
