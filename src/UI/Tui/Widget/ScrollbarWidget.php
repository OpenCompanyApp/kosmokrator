<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Vertical scrollbar indicator for scrollable content.
 *
 * Renders a narrow (1-column) track with a proportional thumb showing the
 * current viewport position relative to total content height.
 *
 * ## Rendering algorithm
 *
 * Given track height H, content length C, viewport length V, and position P:
 *
 *   thumbSize  = max(1, round(H * V / C))
 *   maxScroll  = C - V
 *   fraction   = P / maxScroll         (0.0 = top, 1.0 = bottom)
 *   thumbStart = round((H - thumbSize) * fraction)
 *
 * The track is filled with `trackChar`, rows [thumbStart, thumbStart+thumbSize)
 * are overwritten with `thumbChar`.
 *
 * ## Symbol sets
 *
 * Three built-in symbol sets are provided via constants:
 *
 * - {@see SYMBOLS_DEFAULT} — Unicode block characters (█▓░)
 * - {@see SYMBOLS_MODERN}  — Box-drawing characters (■□ )
 * - {@see SYMBOLS_DOTS}    — Dot characters (●○ )
 *
 * ## Styling
 *
 * Sub-element styles are resolved via the stylesheet using pseudo-element syntax:
 *
 * - `ScrollbarWidget::class . '::track'` → track style (color/attributes)
 * - `ScrollbarWidget::class . '::thumb'` → thumb style (color/attributes)
 *
 * ## Integration
 *
 * The widget receives a {@see ScrollbarState} on each render cycle. The parent
 * container is responsible for computing state from scroll offset and content
 * metrics, either manually or via the reactive signal system.
 *
 * ### Phase 1 — Manual plumbing
 *
 *     $scrollbar->setState(new ScrollbarState(
 *         contentLength:  $contentHeight,
 *         viewportLength: $viewportHeight,
 *         position:       $position,
 *     ));
 *
 * ### Phase 2 — Reactive signal binding
 *
 *     $scrollState = new Computed(function () use ($contentHeight, $viewportHeight, $scrollOffset) {
 *         return new ScrollbarState(
 *             contentLength:  $contentHeight->get(),
 *             viewportLength: $viewportHeight->get(),
 *             position:       max(0, $contentHeight->get() - $viewportHeight->get() - $scrollOffset->get()),
 *         );
 *     });
 *
 *     new Effect(function () use ($scrollState, $scrollbar) {
 *         $scrollbar->setState($scrollState->get());
 *     });
 */
final class ScrollbarWidget extends AbstractWidget
{
    // ── Symbol sets ────────────────────────────────────────────────────────

    /** @var array{track: string, thumb: string} Unicode block characters */
    public const SYMBOLS_DEFAULT = [
        'track' => '░',  // light shade
        'thumb' => '█',  // full block
    ];

    /** @var array{track: string, thumb: string} Box-drawing characters */
    public const SYMBOLS_MODERN = [
        'track' => '□',
        'thumb' => '■',
    ];

    /** @var array{track: string, thumb: string} Dot characters */
    public const SYMBOLS_DOTS = [
        'track' => '○',
        'thumb' => '●',
    ];

    // ── Internal state ────────────────────────────────────────────────────

    /** Current scroll state; null = not scrollable */
    private ?ScrollbarState $state = null;

    /** @var array{track: string, thumb: string} Active symbol set */
    private array $symbols = self::SYMBOLS_DEFAULT;

    // ── Configuration ─────────────────────────────────────────────────────

    /**
     * Set the scrollbar state (content/viewport/position metrics).
     *
     * Pass null to hide the scrollbar (e.g. when content fits the viewport).
     */
    public function setState(?ScrollbarState $state): static
    {
        $this->state = $state;
        $this->invalidate();

        return $this;
    }

    /**
     * Get the current scrollbar state.
     */
    public function getState(): ?ScrollbarState
    {
        return $this->state;
    }

    /**
     * Set the symbol characters for track and thumb.
     *
     * Use one of the SYMBOLS_* constants, or provide a custom array:
     *
     *     $widget->setSymbols(['track' => '│', 'thumb' => '┃']);
     *
     * @param array{track: string, thumb: string} $symbols
     */
    public function setSymbols(array $symbols): static
    {
        $this->symbols = $symbols;
        $this->invalidate();

        return $this;
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * Render the scrollbar into terminal lines.
     *
     * Returns one line per row (each is a single ANSI-styled character).
     * Returns an empty array when no ScrollbarState is set or content fits
     * the viewport.
     *
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        // No state or content fits viewport → nothing to render
        if ($this->state === null || !$this->state->isScrollable()) {
            return [];
        }

        $height = $context->getRows();
        if ($height <= 0) {
            return [];
        }

        $thumbStart = $this->state->thumbStart($height);
        $thumbSize = $this->state->thumbSize($height);

        // Resolve sub-element styles via the stylesheet
        $trackStyled = $this->applyElement('track', $this->symbols['track']);
        $thumbStyled = $this->applyElement('thumb', $this->symbols['thumb']);

        $lines = [];
        for ($row = 0; $row < $height; $row++) {
            $isThumb = $row >= $thumbStart && $row < $thumbStart + $thumbSize;
            $lines[] = $isThumb ? $thumbStyled : $trackStyled;
        }

        return $lines;
    }
}
