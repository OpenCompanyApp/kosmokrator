<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Signal\Signal;

/**
 * A single toast notification instance with reactive animation state.
 *
 * Each toast tracks its own lifecycle: entering → visible → exiting → done.
 * The ToastManager drives phase transitions; the ToastOverlayWidget reads
 * signals for rendering.
 */
final class ToastItem
{
    private static int $idCounter = 0;

    // --- Identity ---
    public readonly int $id;

    public readonly string $message;

    public readonly ToastType $type;

    public readonly int $durationMs;

    // --- Reactive state ---
    /** @var Signal<float> Opacity: 0.0 during entering, 1.0 when visible, fading to 0.0 during exiting */
    public readonly Signal $opacity;

    /** @var Signal<int> Horizontal slide offset (in columns). Starts at toast width, animates to 0. */
    public readonly Signal $slideOffset;

    /** @var Signal<ToastPhase> Current lifecycle phase */
    public readonly Signal $phase;

    // --- Timing ---
    public readonly float $createdAt;

    /**
     * @param  string  $message  Toast body text (plain text, no ANSI)
     * @param  ToastType  $type  Semantic type (determines color, icon, duration)
     * @param  int  $durationMs  Auto-dismiss duration in ms (0 = use type default)
     * @param  float|null  $createdAt  Monotonic timestamp of creation (for ordering)
     */
    public function __construct(
        string $message,
        ToastType $type,
        int $durationMs = 0,
        ?float $createdAt = null,
    ) {
        $this->id = ++self::$idCounter;
        $this->message = $message;
        $this->type = $type;
        $this->durationMs = $durationMs > 0 ? $durationMs : $type->defaultDuration();
        $this->createdAt = $createdAt ?? microtime(true);

        // Initial animation state: invisible, fully off-screen to the right
        $this->opacity = new Signal(0.0);
        $this->slideOffset = new Signal(40); // will be recalculated on first render
        $this->phase = self::signalOfPhase(ToastPhase::Entering);
    }

    /**
     * Convenience factory for common toast types.
     */
    public static function success(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Success, $durationMs);
    }

    public static function warning(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Warning, $durationMs);
    }

    public static function error(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Error, $durationMs);
    }

    public static function info(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Info, $durationMs);
    }

    /**
     * Whether this toast should auto-dismiss (non-sticky).
     */
    public function isAutoDismiss(): bool
    {
        return $this->durationMs > 0;
    }

    /**
     * Begin the exit animation.
     */
    public function dismiss(): void
    {
        if ($this->phase->get() !== ToastPhase::Done) {
            $this->phase->set(ToastPhase::Exiting);
        }
    }

    /**
     * Mark as fully done (ready for removal from the stack).
     */
    public function markDone(): void
    {
        $this->phase->set(ToastPhase::Done);
        $this->opacity->set(0.0);
    }

    /**
     * Create a Signal<ToastPhase> with proper type widening.
     *
     * @return Signal<ToastPhase>
     */
    private static function signalOfPhase(ToastPhase $phase): Signal
    {
        return new Signal($phase);
    }
}
