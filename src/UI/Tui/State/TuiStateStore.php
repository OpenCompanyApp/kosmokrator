<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Kosmokrator\UI\Tui\Signal\Computed;
use Kosmokrator\UI\Tui\Signal\Signal;

/**
 * Centralized reactive state store for the TUI.
 *
 * Every piece of observable UI state lives here as a Signal, so that
 * widgets and renderers can subscribe to fine-grained changes instead of
 * polling or re-rendering everything on each frame.
 *
 * Computed values (e.g. contextPercent) are derived from the signals and
 * auto-update when their dependencies change.
 */
final class TuiStateStore
{
    private Signal $mode;
    private Signal $permissionMode;
    private Signal $tokensIn;
    private Signal $tokensOut;
    private Signal $maxContext;
    private Signal $model;
    private Signal $cost;
    private Signal $phase;
    private Signal $scrollOffset;
    private Signal $sessionTitle;
    private Signal $errorCount;

    private Computed $contextPercent;

    public function __construct()
    {
        $this->mode = new Signal('edit');
        $this->permissionMode = new Signal('guardian');
        $this->tokensIn = new Signal(0);
        $this->tokensOut = new Signal(0);
        $this->maxContext = new Signal(0);
        $this->model = new Signal('');
        $this->cost = new Signal(0.0);
        $this->phase = new Signal('idle');
        $this->scrollOffset = new Signal(0);
        $this->sessionTitle = new Signal('');
        $this->errorCount = new Signal(0);

        $this->contextPercent = new Computed(function (): float {
            $max = $this->maxContext->get();

            if ($max <= 0) {
                return 0.0;
            }

            return ($this->tokensIn->get() / $max) * 100.0;
        });
    }

    // ── mode ─────────────────────────────────────────────────────────────

    public function getMode(): string
    {
        return $this->mode->get();
    }

    public function setMode(string $mode): void
    {
        $this->mode->set($mode);
    }

    public function modeSignal(): Signal
    {
        return $this->mode;
    }

    // ── permissionMode ──────────────────────────────────────────────────

    public function getPermissionMode(): string
    {
        return $this->permissionMode->get();
    }

    public function setPermissionMode(string $permissionMode): void
    {
        $this->permissionMode->set($permissionMode);
    }

    public function permissionModeSignal(): Signal
    {
        return $this->permissionMode;
    }

    // ── tokensIn ────────────────────────────────────────────────────────

    public function getTokensIn(): int
    {
        return $this->tokensIn->get();
    }

    public function setTokensIn(int $tokensIn): void
    {
        $this->tokensIn->set($tokensIn);
    }

    public function tokensInSignal(): Signal
    {
        return $this->tokensIn;
    }

    // ── tokensOut ───────────────────────────────────────────────────────

    public function getTokensOut(): int
    {
        return $this->tokensOut->get();
    }

    public function setTokensOut(int $tokensOut): void
    {
        $this->tokensOut->set($tokensOut);
    }

    public function tokensOutSignal(): Signal
    {
        return $this->tokensOut;
    }

    // ── maxContext ──────────────────────────────────────────────────────

    public function getMaxContext(): int
    {
        return $this->maxContext->get();
    }

    public function setMaxContext(int $maxContext): void
    {
        $this->maxContext->set($maxContext);
    }

    public function maxContextSignal(): Signal
    {
        return $this->maxContext;
    }

    // ── model ───────────────────────────────────────────────────────────

    public function getModel(): string
    {
        return $this->model->get();
    }

    public function setModel(string $model): void
    {
        $this->model->set($model);
    }

    public function modelSignal(): Signal
    {
        return $this->model;
    }

    // ── cost ────────────────────────────────────────────────────────────

    public function getCost(): float
    {
        return $this->cost->get();
    }

    public function setCost(float $cost): void
    {
        $this->cost->set($cost);
    }

    public function costSignal(): Signal
    {
        return $this->cost;
    }

    // ── phase ───────────────────────────────────────────────────────────

    public function getPhase(): string
    {
        return $this->phase->get();
    }

    public function setPhase(string $phase): void
    {
        $this->phase->set($phase);
    }

    public function phaseSignal(): Signal
    {
        return $this->phase;
    }

    // ── scrollOffset ────────────────────────────────────────────────────

    public function getScrollOffset(): int
    {
        return $this->scrollOffset->get();
    }

    public function setScrollOffset(int $scrollOffset): void
    {
        $this->scrollOffset->set($scrollOffset);
    }

    public function scrollOffsetSignal(): Signal
    {
        return $this->scrollOffset;
    }

    // ── sessionTitle ────────────────────────────────────────────────────

    public function getSessionTitle(): string
    {
        return $this->sessionTitle->get();
    }

    public function setSessionTitle(string $sessionTitle): void
    {
        $this->sessionTitle->set($sessionTitle);
    }

    public function sessionTitleSignal(): Signal
    {
        return $this->sessionTitle;
    }

    // ── errorCount ──────────────────────────────────────────────────────

    public function getErrorCount(): int
    {
        return $this->errorCount->get();
    }

    public function setErrorCount(int $errorCount): void
    {
        $this->errorCount->set($errorCount);
    }

    public function errorCountSignal(): Signal
    {
        return $this->errorCount;
    }

    // ── computed ────────────────────────────────────────────────────────

    public function getContextPercent(): float
    {
        return $this->contextPercent->get();
    }

    public function contextPercentComputed(): Computed
    {
        return $this->contextPercent;
    }
}
