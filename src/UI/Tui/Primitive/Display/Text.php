<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Display;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Reactive text widget bound to a Signal or static string.
 *
 * Reads the signal inside syncFromSignals() (called every frame via beforeRender).
 * Only invalidates when the text or color actually changed.
 *
 * Usage:
 *   Text::of($state->modeLabelSignal())           — reactive
 *   Text::of($state->modeLabelSignal())->bold()   — with modifier
 *   Text::of('static text')                        — static (wraps plain string as signal)
 */
final class Text extends ReactiveWidget
{
    private string $text = '';

    private string $color = '';

    private bool $bold = false;

    private bool $dim = false;

    private int $truncate = 0;

    /** @var Signal<string> */
    private readonly Signal $textSignal;

    /** @var Signal<string>|null */
    private ?Signal $colorSignal = null;

    private function __construct(Signal $textSignal)
    {
        $this->textSignal = $textSignal;
    }

    /**
     * Create from a signal (reactive) or a plain string (static).
     */
    public static function of(Signal|string $text): self
    {
        if (is_string($text)) {
            $text = new Signal($text);
        }

        return new self($text);
    }

    /**
     * Bind color to a signal (reactive) or static string.
     */
    public function color(Signal|string $color): self
    {
        if (is_string($color)) {
            $color = new Signal($color);
        }
        $this->colorSignal = $color;

        return $this;
    }

    public function bold(bool $bold = true): self
    {
        $this->bold = $bold;

        return $this;
    }

    public function dim(bool $dim = true): self
    {
        $this->dim = $dim;

        return $this;
    }

    /**
     * Truncate to max visible columns. 0 = no truncation.
     */
    public function truncate(int $maxWidth): self
    {
        $this->truncate = $maxWidth;

        return $this;
    }

    public function syncFromSignals(): bool
    {
        $newText = $this->textSignal->get();

        $newColor = '';
        if ($this->colorSignal !== null) {
            $newColor = $this->colorSignal->get();
        }

        if ($newText === $this->text && $newColor === $this->color) {
            return false;
        }

        $this->text = $newText;
        $this->color = $newColor;

        return true;
    }

    public function render(RenderContext $context): array
    {
        $text = $this->text;

        if ($this->truncate > 0 && mb_strlen($text) > $this->truncate) {
            $text = mb_substr($text, 0, $this->truncate - 1).'…';
        }

        $reset = "\033[0m";

        $parts = [];
        if ($this->color !== '') {
            $parts[] = $this->color;
        }
        if ($this->bold) {
            $parts[] = "\033[1m";
        }
        if ($this->dim) {
            $parts[] = "\033[2m";
        }

        $prefix = implode('', $parts);
        $line = ($prefix !== '' ? $prefix.$text.$reset : $text);

        if ($line === '') {
            return [];
        }

        return TextWrapper::wrapTextWithAnsi($line, max(1, $context->getColumns()));
    }
}
