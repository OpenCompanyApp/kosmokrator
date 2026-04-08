<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Toast;

use Kosmokrator\UI\TerminalNotification;
use Revolt\EventLoop;
use Rubedo\Signal;

/**
 * Manages the lifecycle of toast notifications.
 *
 * Responsibilities:
 * 1. Add toasts to the visible stack (max 5)
 * 2. Drive entrance/exit animations via timer callbacks
 * 3. Auto-dismiss toasts after their configured duration
 * 4. Remove completed toasts from the stack
 * 5. Bridge to TerminalNotification for desktop notifications
 * 6. Provide a simple static API for callers
 *
 * Usage:
 *   ToastManager::show('File saved', ToastType::Success);
 *   ToastManager::error('Permission denied');
 *   ToastManager::info('Mode: Edit');
 */
final class ToastManager
{
    private const MAX_VISIBLE = 5;

    private const ENTRANCE_DURATION_MS = 150;

    private const EXIT_DURATION_MS = 200;

    private const ANIMATION_FRAME_MS = 16; // ~60fps

    /** @var Signal<list<ToastItem>> The current toast stack (newest first) */
    public readonly Signal $toasts;

    /** @var array<string, string> Active timer IDs for cleanup */
    private array $timers = [];

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var bool Whether to also fire TerminalNotification (desktop) for errors */
    private bool $desktopNotifyOnError = true;

    private function __construct()
    {
        $this->toasts = self::signalOfList();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    // --- Static convenience API ---

    public static function show(string $message, ToastType $type, int $durationMs = 0): ToastItem
    {
        return self::getInstance()->addToast(new ToastItem($message, $type, $durationMs));
    }

    public static function success(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Success, $durationMs);
    }

    public static function warning(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Warning, $durationMs);
    }

    public static function error(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Error, $durationMs);
    }

    public static function info(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Info, $durationMs);
    }

    /**
     * Dismiss all active toasts immediately (with exit animation).
     */
    public static function dismissAll(): void
    {
        self::getInstance()->dismissAllToasts();
    }

    // --- Instance API ---

    /**
     * Add a toast item to the stack and begin its lifecycle.
     */
    public function addToast(ToastItem $toast): ToastItem
    {
        $stack = $this->toasts->get();

        // Enforce max visible: dismiss oldest if stack is full
        if (count($stack) >= self::MAX_VISIBLE) {
            $oldest = $stack[array_key_last($stack)];
            $this->dismissToast($oldest);
            $stack = array_filter($stack, fn (ToastItem $t) => $t->id !== $oldest->id);
        }

        // Prepend (newest first = rendered at top)
        array_unshift($stack, $toast);
        $stack = array_values($stack);
        $this->toasts->set($stack);

        // Start entrance animation
        $this->startEntranceAnimation($toast);

        // Bridge to desktop notification for errors
        if ($this->desktopNotifyOnError && $toast->type === ToastType::Error) {
            TerminalNotification::notify();
        }

        return $toast;
    }

    /**
     * Dismiss a specific toast (starts exit animation).
     */
    public function dismissToast(ToastItem $toast): void
    {
        if ($toast->phase->get() === ToastPhase::Exiting
            || $toast->phase->get() === ToastPhase::Done
        ) {
            return;
        }

        $toast->dismiss();
        $this->cancelTimers($toast->id);
        $this->startExitAnimation($toast);
    }

    /**
     * Dismiss all toasts with exit animation.
     */
    public function dismissAllToasts(): void
    {
        foreach ($this->toasts->get() as $toast) {
            $this->dismissToast($toast);
        }
    }

    /**
     * Remove a toast from the stack (called after exit animation completes).
     */
    public function removeToast(ToastItem $toast): void
    {
        $stack = array_values(array_filter(
            $this->toasts->get(),
            fn (ToastItem $t) => $t->id !== $toast->id,
        ));
        $this->toasts->set($stack);
        $this->cancelTimers($toast->id);
    }

    /**
     * Find a toast by its screen coordinates (for mouse click dismissal).
     *
     * @param  int  $row  Screen row (1-based)
     * @param  int  $col  Screen column (1-based)
     * @param  int  $viewportRows  Total viewport rows
     * @param  int  $viewportCols  Total viewport columns
     * @param  int  $statusBarRows  Height of the status bar area
     * @return ToastItem|null The toast at those coordinates, or null
     */
    public function getToastAt(
        int $row,
        int $col,
        int $viewportRows,
        int $viewportCols,
        int $statusBarRows = 1,
    ): ?ToastItem {
        $marginRight = 2;
        $marginBottom = $statusBarRows + 1;
        $toastMaxWidth = min(50, $viewportCols - $marginRight - 4);

        $baseRow = $viewportRows - $marginBottom;
        $currentRow = $baseRow;

        foreach ($this->toasts->get() as $toast) {
            if ($toast->phase->get() === ToastPhase::Done) {
                continue;
            }

            $visibleLines = $this->calculateToastHeight($toast->message, $toastMaxWidth - 4);
            $toastTop = $currentRow - $visibleLines + 1;
            $toastLeft = $viewportCols - $marginRight - $toastMaxWidth;
            $toastRight = $viewportCols - $marginRight;

            if ($row >= $toastTop && $row <= $currentRow
                && $col >= $toastLeft && $col <= $toastRight
            ) {
                return $toast;
            }

            $currentRow = $toastTop - 1; // 1-row gap between toasts
        }

        return null;
    }

    /**
     * Enable/disable desktop notification bridging for error toasts.
     */
    public function setDesktopNotifyOnError(bool $enabled): void
    {
        $this->desktopNotifyOnError = $enabled;
    }

    /**
     * Create a Signal<list<ToastItem>> with proper type widening.
     *
     * Phpstan infers Signal<array{}> from new Signal([]), but the property
     * is typed as Signal<list<ToastItem>>. This factory forces the template
     * parameter via @param annotation.
     *
     * @param  list<ToastItem>  $initial
     * @return Signal<list<ToastItem>>
     */
    private static function signalOfList(array $initial = []): Signal
    {
        return new Signal($initial);
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->dismissAllToasts();
            self::$instance = null;
        }
    }

    // --- Private: animation lifecycle ---

    /**
     * Animate a toast's entrance: slide from right + fade in.
     */
    private function startEntranceAnimation(ToastItem $toast): void
    {
        $frames = (int) ceil(self::ENTRANCE_DURATION_MS / self::ANIMATION_FRAME_MS);
        $slideStart = 30; // columns off-screen to the right
        $frameDuration = self::ENTRANCE_DURATION_MS / $frames;

        $toast->slideOffset->set($slideStart);
        $toast->opacity->set(0.0);

        $currentFrame = 0;
        $timerId = EventLoop::repeat(
            $frameDuration / 1000,
            function () use ($toast, &$currentFrame, $frames, $slideStart) {
                $currentFrame++;
                $progress = min(1.0, $currentFrame / $frames);

                // Ease-out curve for smooth deceleration
                $eased = 1.0 - (1.0 - $progress) ** 2;

                $toast->slideOffset->set((int) round($slideStart * (1.0 - $eased)));
                $toast->opacity->set($eased);

                if ($progress >= 1.0) {
                    $toast->phase->set(ToastPhase::Visible);
                    $this->cancelTimers($toast->id);
                    $this->scheduleAutoDismiss($toast);
                }
            },
        );

        $this->timers[$toast->id.'_entrance'] = $timerId;
    }

    /**
     * Schedule auto-dismissal after the toast's configured duration.
     */
    private function scheduleAutoDismiss(ToastItem $toast): void
    {
        if (! $toast->isAutoDismiss()) {
            return; // Sticky toast — no auto-dismiss
        }

        $timerId = EventLoop::delay(
            $toast->durationMs / 1000,
            function () use ($toast): void {
                if ($toast->phase->get() === ToastPhase::Visible) {
                    $this->dismissToast($toast);
                }
            },
        );

        $this->timers[$toast->id.'_auto'] = $timerId;
    }

    /**
     * Animate a toast's exit: fade out.
     */
    private function startExitAnimation(ToastItem $toast): void
    {
        $frames = (int) ceil(self::EXIT_DURATION_MS / self::ANIMATION_FRAME_MS);
        $frameDuration = self::EXIT_DURATION_MS / $frames;

        $currentFrame = 0;
        $timerId = EventLoop::repeat(
            $frameDuration / 1000,
            function () use ($toast, &$currentFrame, $frames) {
                $currentFrame++;
                $progress = min(1.0, $currentFrame / $frames);

                // Ease-in for fade-out
                $eased = $progress ** 2;
                $toast->opacity->set(1.0 - $eased);

                if ($progress >= 1.0) {
                    $toast->markDone();
                    $this->cancelTimers($toast->id);
                    $this->removeToast($toast);
                }
            },
        );

        $this->timers[$toast->id.'_exit'] = $timerId;
    }

    /**
     * Cancel all timers for a given toast ID.
     */
    private function cancelTimers(int $toastId): void
    {
        foreach (['_entrance', '_auto', '_exit'] as $suffix) {
            $key = $toastId.$suffix;
            if (isset($this->timers[$key])) {
                EventLoop::cancel($this->timers[$key]);
                unset($this->timers[$key]);
            }
        }
    }

    /**
     * Calculate the rendered height (in terminal rows) of a toast message.
     */
    private function calculateToastHeight(string $message, int $innerWidth): int
    {
        // 1 line top border + N content lines + 1 line bottom border
        $lines = 1; // top border
        $wrapped = $this->wrapText($message, $innerWidth);
        $lines += count($wrapped);
        $lines += 1; // bottom border

        return $lines;
    }

    /**
     * Simple word-wrap to fit within a visible character width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if (mb_strwidth($text) <= $width) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current.' '.$word;
            if (mb_strwidth($test) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}
