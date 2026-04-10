<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Amp\DeferredCancellation;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * Reactive thinking loader — self-managing lifecycle.
 *
 * Reads hasThinkingLoaderSignal to show/hide. Reads thinkingPhraseSignal
 * and breathColorSignal for display. Creates/destroys the underlying
 * CancellableLoaderWidget and manages its own spinner timer.
 *
 * Replaces the imperative loader management in TuiAnimationManager.
 */
final class ThinkingLoaderWidget extends ReactiveWidget implements ParentInterface
{
    private ?CancellableLoaderWidget $loader = null;

    private bool $mounted = false;

    private string $lastPhrase = '';

    private string $lastColor = '';

    private bool $lastShowElapsed = false;

    private int $lastElapsed = -1;

    private readonly TuiStateStore $state;

    private static array $spinners = [
        'cosmos' => ['✦', '✧', '⊛', '◈', '⊛', '✧'],
        'planets' => ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆'],
        'stars' => ['⋆', '✧', '★', '✦', '★', '✧'],
        'ouroboros' => ['◴', '◷', '◶', '◵'],
        'oracle' => ['◉', '◎', '◉', '○', '◎', '○'],
        'runes' => ['ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ'],
        'fate' => ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'],
        'sigil' => ['᛭', '⊹', '✳', '✴', '✳', '⊹'],
        'serpent' => ['∿', '≀', '∾', '≀'],
        'eclipse' => ['◐', '◓', '◑', '◒'],
        'hourglass' => ['⧗', '⧖', '⧗', '⧖'],
        'trident' => ['ψ', 'Ψ', 'ψ', '⊥'],
        'aether' => ['·', '∘', '○', '◌', '○', '∘'],
        'elements' => ['🜁', '🜂', '🜃', '🜄'],
    ];

    private static array $phrases = [
        '◈ Consulting the Oracle at Delphi...',
        '♃ Aligning the celestial spheres...',
        '⚡ Channeling Prometheus\' fire...',
        '♄ Weaving the threads of Fate...',
        '☽ Reading the astral charts...',
        '♂ Invoking the nine Muses...',
        '♆ Traversing the Aether...',
        '♅ Deciphering cosmic glyphs...',
        '⚡ Summoning Athena\'s wisdom...',
        '☉ Attuning to the Music of the Spheres...',
        '♃ Gazing into the cosmic void...',
        '◈ Unraveling the Labyrinth...',
        '♆ Communing with the Titans...',
        '♄ Forging in Hephaestus\' workshop...',
        '☽ Scrying the heavens...',
    ];

    public function __construct(TuiStateStore $state)
    {
        $this->state = $state;
        $this->setId('thinking-loader');
    }

    /**
     * Set the cancellation token for the loader's cancel button.
     */
    public function setCancellation(?DeferredCancellation $cancellation): void
    {
        // Will be wired on next mount
        $this->cancellation = $cancellation;
    }

    private ?DeferredCancellation $cancellation = null;

    public function syncFromSignals(): bool
    {
        $shouldShow = $this->state->getHasThinkingLoader();

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

        $newPhrase = $this->state->getThinkingPhrase() ?? '';
        $newColor = $this->state->getBreathColor() ?? '';
        $showElapsed = ! $this->state->getHasSubagentActivity();
        $elapsed = (int) (microtime(true) - $this->state->getThinkingStartTime());

        if (
            $newPhrase === $this->lastPhrase
            && $newColor === $this->lastColor
            && $showElapsed === $this->lastShowElapsed
            && $elapsed === $this->lastElapsed
        ) {
            return false;
        }

        $this->lastPhrase = $newPhrase;
        $this->lastColor = $newColor;
        $this->lastShowElapsed = $showElapsed;
        $this->lastElapsed = $elapsed;

        $this->updateMessage($newPhrase, $newColor, $showElapsed, $elapsed);

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

        $this->registerSpinners();

        $phrase = self::$phrases[array_rand(self::$phrases)];
        $this->state->setThinkingPhrase($phrase);

        $spinnerNames = array_keys(self::$spinners);
        $spinnerIdx = $this->state->getSpinnerIndex();
        $spinnerName = $spinnerNames[$spinnerIdx % count($spinnerNames)];

        $this->loader = new CancellableLoaderWidget($phrase);
        $this->loader->setId('loader');
        $this->loader->setSpinner($spinnerName);
        $this->loader->setIntervalMs(120);
        $this->loader->start();
        $this->attachLoader();

        $cancellation = $this->cancellation ?? $this->state->getRequestCancellation();
        $this->loader->onCancel(function () use ($cancellation): void {
            $cancellation?->cancel();
        });

        $this->mounted = true;

        $color = $this->state->getBreathColor() ?? '';
        $showElapsed = ! $this->state->getHasSubagentActivity();
        $elapsed = (int) (microtime(true) - $this->state->getThinkingStartTime());

        $this->lastPhrase = $phrase;
        $this->lastColor = $color;
        $this->lastShowElapsed = $showElapsed;
        $this->lastElapsed = $elapsed;

        $this->updateMessage($phrase, $color, $showElapsed, $elapsed);
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
        $this->lastColor = '';
        $this->lastShowElapsed = false;
        $this->lastElapsed = -1;
    }

    private function updateMessage(string $phrase, string $color, bool $showElapsed, int $elapsed): void
    {
        if ($this->loader === null) {
            return;
        }

        $r = "\033[0m";
        $message = "{$color}{$phrase}{$r}";

        if ($showElapsed) {
            $dim = "\033[38;5;245m";
            $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
            $message .= "{$dim} · {$formatted}{$r}";
        }

        $this->loader->setMessage($message);
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

    public static function registerSpinners(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        foreach (self::$spinners as $name => $frames) {
            CancellableLoaderWidget::addSpinner($name, $frames);
        }
        $registered = true;
    }
}
