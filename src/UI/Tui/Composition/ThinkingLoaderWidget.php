<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Amp\DeferredCancellation;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Revolt\EventLoop;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;

/**
 * Reactive thinking loader — self-managing lifecycle.
 *
 * Reads hasThinkingLoaderSignal to show/hide. Reads thinkingPhraseSignal
 * and breathColorSignal for display. Creates/destroys the underlying
 * CancellableLoaderWidget and manages its own spinner timer.
 *
 * Replaces the imperative loader management in TuiAnimationManager.
 */
final class ThinkingLoaderWidget extends ReactiveWidget
{
    private ?CancellableLoaderWidget $loader = null;

    private ?string $timerId = null;

    private bool $mounted = false;

    private string $lastPhrase = '';

    private string $lastColor = '';

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

        // Update message/color reactively
        $newPhrase = $this->state->getThinkingPhrase() ?? '';
        $newColor = $this->state->getBreathColor() ?? '';

        if ($newPhrase === $this->lastPhrase && $newColor === $this->lastColor) {
            return false;
        }

        $this->lastPhrase = $newPhrase;
        $this->lastColor = $newColor;

        if ($this->loader !== null) {
            $r = "\033[0m";
            $message = "{$newColor}{$newPhrase}{$r}";

            if (! $this->state->getHasSubagentActivity()) {
                $dim = "\033[38;5;245m";
                $elapsed = (int) (microtime(true) - $this->state->getThinkingStartTime());
                $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $message .= "{$dim} · {$formatted}{$r}";
            }

            $this->loader->setMessage($message);
        }

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

        $cancellation = $this->cancellation ?? $this->state->getRequestCancellation();
        $this->loader->onCancel(function () use ($cancellation): void {
            $cancellation?->cancel();
        });

        $this->lastPhrase = $phrase;
        $this->lastColor = '';
        $this->mounted = true;
    }

    private function unmount(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }

        if ($this->loader !== null) {
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->loader = null;
        }

        $this->mounted = false;
        $this->lastPhrase = '';
        $this->lastColor = '';
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
