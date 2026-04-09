<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Display;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Reactive markdown widget bound to a Signal<string>.
 *
 * Wraps MarkdownWidget. Updates content when the signal changes.
 *
 * Usage:
 *   Markdown::of($state->activeResponseTextSignal())
 */
final class Markdown extends ReactiveWidget
{
    private string $text = '';

    private readonly Signal $textSignal;

    private function __construct(Signal $textSignal)
    {
        $this->textSignal = $textSignal;
    }

    public static function of(Signal $textSignal): self
    {
        return new self($textSignal);
    }

    protected function syncFromSignals(): bool
    {
        $new = $this->textSignal->get();

        if ($new === $this->text) {
            return false;
        }

        $this->text = $new;

        return true;
    }

    public function render(RenderContext $context): array
    {
        // Delegate to Symfony TUI's MarkdownWidget rendering
        $md = new MarkdownWidget($this->text);
        $md->addStyleClass('response');

        return $md->render($context);
    }
}
