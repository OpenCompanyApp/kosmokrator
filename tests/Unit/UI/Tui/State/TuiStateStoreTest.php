<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\State;

use Kosmokrator\UI\Tui\Signal\Effect;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;

final class TuiStateStoreTest extends TestCase
{
    // ── Round-trip: get → set → get ─────────────────────────────────────

    public function test_mode_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame('edit', $store->getMode());
        $store->setMode('plan');
        $this->assertSame('plan', $store->getMode());
        $store->setMode('ask');
        $this->assertSame('ask', $store->getMode());
    }

    public function test_permission_mode_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame('guardian', $store->getPermissionMode());
        $store->setPermissionMode('argus');
        $this->assertSame('argus', $store->getPermissionMode());
        $store->setPermissionMode('prometheus');
        $this->assertSame('prometheus', $store->getPermissionMode());
    }

    public function test_tokens_in_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0, $store->getTokensIn());
        $store->setTokensIn(42_000);
        $this->assertSame(42_000, $store->getTokensIn());
    }

    public function test_tokens_out_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0, $store->getTokensOut());
        $store->setTokensOut(1_500);
        $this->assertSame(1_500, $store->getTokensOut());
    }

    public function test_max_context_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0, $store->getMaxContext());
        $store->setMaxContext(200_000);
        $this->assertSame(200_000, $store->getMaxContext());
    }

    public function test_model_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame('', $store->getModel());
        $store->setModel('claude-sonnet-4-20250514');
        $this->assertSame('claude-sonnet-4-20250514', $store->getModel());
    }

    public function test_cost_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0.0, $store->getCost());
        $store->setCost(0.042);
        $this->assertSame(0.042, $store->getCost());
    }

    public function test_phase_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame('idle', $store->getPhase());
        $store->setPhase('thinking');
        $this->assertSame('thinking', $store->getPhase());
        $store->setPhase('tools');
        $this->assertSame('tools', $store->getPhase());
        $store->setPhase('compact');
        $this->assertSame('compact', $store->getPhase());
    }

    public function test_scroll_offset_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0, $store->getScrollOffset());
        $store->setScrollOffset(120);
        $this->assertSame(120, $store->getScrollOffset());
    }

    public function test_session_title_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame('', $store->getSessionTitle());
        $store->setSessionTitle('My Session');
        $this->assertSame('My Session', $store->getSessionTitle());
    }

    public function test_error_count_round_trip(): void
    {
        $store = new TuiStateStore();
        $this->assertSame(0, $store->getErrorCount());
        $store->setErrorCount(3);
        $this->assertSame(3, $store->getErrorCount());
    }

    // ── Computed: contextPercent ─────────────────────────────────────────

    public function test_context_percent_with_valid_context(): void
    {
        $store = new TuiStateStore();
        $store->setMaxContext(200_000);
        $store->setTokensIn(100_000);

        $this->assertSame(50.0, $store->getContextPercent());
    }

    public function test_context_percent_zero_max_context(): void
    {
        $store = new TuiStateStore();
        $store->setMaxContext(0);
        $store->setTokensIn(5_000);

        $this->assertSame(0.0, $store->getContextPercent());
    }

    public function test_context_percent_updates_reactively(): void
    {
        $store = new TuiStateStore();
        $store->setMaxContext(100_000);
        $store->setTokensIn(25_000);

        $this->assertSame(25.0, $store->getContextPercent());

        $store->setTokensIn(75_000);
        $this->assertSame(75.0, $store->getContextPercent());

        $store->setMaxContext(50_000);
        $this->assertSame(150.0, $store->getContextPercent()); // can exceed 100
    }

    // ── Signal reactivity ────────────────────────────────────────────────

    public function test_signal_accessor_returns_same_underlying_signal(): void
    {
        $store = new TuiStateStore();
        $signal = $store->modeSignal();

        // Mutate through signal, read through getter
        $signal->set('plan');
        $this->assertSame('plan', $store->getMode());
    }

    public function test_signal_subscribe_triggers_on_set(): void
    {
        $store = new TuiStateStore();
        $received = null;
        $store->modeSignal()->subscribe(function (string $value) use (&$received): void {
            $received = $value;
        });

        $store->setMode('ask');
        $this->assertSame('ask', $received);
    }

    public function test_effect_tracks_signal_change(): void
    {
        $store = new TuiStateStore();
        $captured = [];

        new Effect(function () use ($store, &$captured): void {
            $captured[] = $store->getPhase();
        });

        $this->assertCount(1, $captured); // initial execution
        $this->assertSame('idle', $captured[0]);

        $store->setPhase('thinking');
        $this->assertCount(2, $captured);
        $this->assertSame('thinking', $captured[1]);
    }

    public function test_multiple_signals_subscribe_independently(): void
    {
        $store = new TuiStateStore();
        $modeChanges = 0;
        $phaseChanges = 0;

        $store->modeSignal()->subscribe(function () use (&$modeChanges): void {
            $modeChanges++;
        });
        $store->phaseSignal()->subscribe(function () use (&$phaseChanges): void {
            $phaseChanges++;
        });

        $store->setMode('plan');
        $this->assertSame(1, $modeChanges);
        $this->assertSame(0, $phaseChanges);

        $store->setPhase('thinking');
        $this->assertSame(1, $modeChanges);
        $this->assertSame(1, $phaseChanges);
    }
}
