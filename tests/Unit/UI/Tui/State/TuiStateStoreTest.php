<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\State;

use Amp\DeferredCancellation;
use Kosmokrator\UI\Tui\Signal\BatchScope;
use Kosmokrator\UI\Tui\Signal\Computed;
use Kosmokrator\UI\Tui\Signal\Effect;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;

final class TuiStateStoreTest extends TestCase
{
    // ── Round-trip: get → set → get ─────────────────────────────────────

    public function test_mode_label_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('Edit', $store->getModeLabel());
        $store->setModeLabel('Plan');
        $this->assertSame('Plan', $store->getModeLabel());
        $store->setModeLabel('Ask');
        $this->assertSame('Ask', $store->getModeLabel());
    }

    public function test_permission_label_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('Guardian ◈', $store->getPermissionLabel());
        $store->setPermissionLabel('Argus');
        $this->assertSame('Argus', $store->getPermissionLabel());
        $store->setPermissionLabel('Prometheus');
        $this->assertSame('Prometheus', $store->getPermissionLabel());
    }

    public function test_tokens_in_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getTokensIn());
        $store->setTokensIn(42_000);
        $this->assertSame(42_000, $store->getTokensIn());
    }

    public function test_tokens_out_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getTokensOut());
        $store->setTokensOut(1_500);
        $this->assertSame(1_500, $store->getTokensOut());
    }

    public function test_max_context_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getMaxContext());
        $store->setMaxContext(200_000);
        $this->assertSame(200_000, $store->getMaxContext());
    }

    public function test_model_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('', $store->getModel());
        $store->setModel('claude-sonnet-4-20250514');
        $this->assertSame('claude-sonnet-4-20250514', $store->getModel());
    }

    public function test_cost_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getCost());
        $store->setCost(0.042);
        $this->assertSame(0.042, $store->getCost());
    }

    public function test_phase_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('idle', $store->getPhase());
        $store->setPhase('thinking');
        $this->assertSame('thinking', $store->getPhase());
        $store->setPhase('tools');
        $this->assertSame('tools', $store->getPhase());
        $store->setPhase('compact');
        $this->assertSame('compact', $store->getPhase());
    }

    // ── Mode color ──────────────────────────────────────────────────────

    public function test_mode_color_round_trip(): void
    {
        $store = new TuiStateStore;
        $original = $store->getModeColor();
        $this->assertIsString($original);
        $new = "\033[38;2;160;120;255m";
        $store->setModeColor($new);
        $this->assertSame($new, $store->getModeColor());
    }

    public function test_permission_color_round_trip(): void
    {
        $store = new TuiStateStore;
        $original = $store->getPermissionColor();
        $this->assertIsString($original);
        $new = "\033[38;2;255;180;60m";
        $store->setPermissionColor($new);
        $this->assertSame($new, $store->getPermissionColor());
    }

    // ── Status detail ───────────────────────────────────────────────────

    public function test_status_detail_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('Ready', $store->getStatusDetail());
        $store->setStatusDetail('Processing...');
        $this->assertSame('Processing...', $store->getStatusDetail());
    }

    // ── Scroll / History ────────────────────────────────────────────────

    public function test_scroll_offset_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getScrollOffset());
        $store->setScrollOffset(20);
        $this->assertSame(20, $store->getScrollOffset());
    }

    public function test_has_hidden_activity_below_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getHasHiddenActivityBelow());
        $store->setHasHiddenActivityBelow(true);
        $this->assertTrue($store->getHasHiddenActivityBelow());
    }

    // ── Streaming state ─────────────────────────────────────────────────

    public function test_active_response_defaults_null(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getActiveResponse());
        $this->assertFalse($store->getActiveResponseIsAnsi());
    }

    public function test_active_response_round_trip(): void
    {
        $store = new TuiStateStore;
        $widget = new \stdClass; // Simulate a widget
        $store->setActiveResponse($widget);
        $this->assertSame($widget, $store->getActiveResponse());

        $store->setActiveResponseIsAnsi(true);
        $this->assertTrue($store->getActiveResponseIsAnsi());
    }

    // ── Input / Prompt state ────────────────────────────────────────────

    public function test_pending_editor_restore_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getPendingEditorRestore());
        $store->setPendingEditorRestore('saved text');
        $this->assertSame('saved text', $store->getPendingEditorRestore());
        $store->setPendingEditorRestore(null);
        $this->assertNull($store->getPendingEditorRestore());
    }

    public function test_request_cancellation_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getRequestCancellation());
        $dc = new DeferredCancellation;
        $store->setRequestCancellation($dc);
        $this->assertSame($dc, $store->getRequestCancellation());
        $store->setRequestCancellation(null);
        $this->assertNull($store->getRequestCancellation());
    }

    // ── Message queue ───────────────────────────────────────────────────

    public function test_message_queue_push_and_shift(): void
    {
        $store = new TuiStateStore;
        $this->assertSame([], $store->getMessageQueue());

        $store->pushMessage('hello');
        $store->pushMessage('world');

        $this->assertSame('hello', $store->shiftMessage());
        $this->assertSame('world', $store->shiftMessage());
        $this->assertNull($store->shiftMessage());
    }

    public function test_message_queue_shift_on_empty(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->shiftMessage());
    }

    // ── Question recap ──────────────────────────────────────────────────

    public function test_question_recap_push_and_drain(): void
    {
        $store = new TuiStateStore;
        $this->assertSame([], $store->getPendingQuestionRecap());

        $store->pushQuestionRecap('What?', 'This', true);
        $store->pushQuestionRecap('How?', 'That', true, true);

        $recap = $store->drainQuestionRecap();
        $this->assertCount(2, $recap);
        $this->assertSame('What?', $recap[0]['question']);
        $this->assertSame('How?', $recap[1]['question']);
        $this->assertTrue($recap[1]['recommended']);

        // After drain, should be empty
        $this->assertSame([], $store->drainQuestionRecap());
    }

    // ── Animation state ─────────────────────────────────────────────────

    public function test_breath_color_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getBreathColor());
        $store->setBreathColor("\033[38;2;112;160;208m");
        $this->assertSame("\033[38;2;112;160;208m", $store->getBreathColor());
    }

    public function test_thinking_phrase_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getThinkingPhrase());
        $store->setThinkingPhrase('Consulting the Oracle...');
        $this->assertSame('Consulting the Oracle...', $store->getThinkingPhrase());
    }

    public function test_thinking_start_time_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0.0, $store->getThinkingStartTime());
        $now = microtime(true);
        $store->setThinkingStartTime($now);
        $this->assertSame($now, $store->getThinkingStartTime());
    }

    public function test_breath_tick_increment(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getBreathTick());
        $store->tickBreath();
        $this->assertSame(1, $store->getBreathTick());
        $store->tickBreath();
        $this->assertSame(2, $store->getBreathTick());
    }

    public function test_compacting_breath_tick_increment(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getCompactingBreathTick());
        $store->tickCompactingBreath();
        $this->assertSame(1, $store->getCompactingBreathTick());
    }

    public function test_spinner_allocation(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->allocateSpinner());
        $this->assertSame(1, $store->allocateSpinner());
        $this->assertSame(2, $store->allocateSpinner());
        $this->assertSame(3, $store->getSpinnerIndex());
    }

    // ── Subagent state ──────────────────────────────────────────────────

    public function test_batch_displayed_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getBatchDisplayed());
        $store->setBatchDisplayed(true);
        $this->assertTrue($store->getBatchDisplayed());
    }

    public function test_loader_breath_tick_increment(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getLoaderBreathTick());
        $store->tickLoaderBreath();
        $this->assertSame(1, $store->getLoaderBreathTick());
    }

    public function test_cached_loader_label_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('Agents running...', $store->getCachedLoaderLabel());
        $store->setCachedLoaderLabel('3 agents active');
        $this->assertSame('3 agents active', $store->getCachedLoaderLabel());
    }

    public function test_has_running_agents_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getHasRunningAgents());
        $store->setHasRunningAgents(true);
        $this->assertTrue($store->getHasRunningAgents());
    }

    // ── Tool state ──────────────────────────────────────────────────────

    public function test_last_tool_args_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame([], $store->getLastToolArgs());
        $store->setLastToolArgs(['path' => 'src/Foo.php']);
        $this->assertSame(['path' => 'src/Foo.php'], $store->getLastToolArgs());
    }

    public function test_last_tool_args_by_name_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame([], $store->getLastToolArgsByName());
        $store->setLastToolArgsByName(['file_read' => ['path' => 'a.php']]);
        $this->assertSame(['file_read' => ['path' => 'a.php']], $store->getLastToolArgsByName());
    }

    public function test_tool_executing_preview_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertNull($store->getToolExecutingPreview());
        $store->setToolExecutingPreview('running npm test');
        $this->assertSame('running npm test', $store->getToolExecutingPreview());
    }

    // ── Modal state ─────────────────────────────────────────────────────

    public function test_active_modal_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getActiveModal());
        $store->setActiveModal(true);
        $this->assertTrue($store->getActiveModal());
    }

    // ── Task / Has tasks ────────────────────────────────────────────────

    public function test_has_tasks_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getHasTasks());
        $store->setHasTasks(true);
        $this->assertTrue($store->getHasTasks());
    }

    public function test_has_subagent_activity_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getHasSubagentActivity());
        $store->setHasSubagentActivity(true);
        $this->assertTrue($store->getHasSubagentActivity());
    }

    // ── Render trigger ──────────────────────────────────────────────────

    public function test_render_trigger_increments(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getRenderTrigger());
        $store->triggerRender();
        $this->assertSame(1, $store->getRenderTrigger());
        $store->triggerRender();
        $this->assertSame(2, $store->getRenderTrigger());
    }

    // ── Computed: contextPercent ─────────────────────────────────────────

    public function test_context_percent_with_tokens(): void
    {
        $store = new TuiStateStore;
        $store->setMaxContext(200_000);
        $store->setTokensIn(100_000);

        $this->assertSame(50.0, $store->getContextPercent());
    }

    public function test_context_percent_with_zero_max(): void
    {
        $store = new TuiStateStore;
        $store->setMaxContext(0);
        $store->setTokensIn(5_000);

        $this->assertSame(0.0, $store->getContextPercent());
    }

    public function test_context_percent_with_null_max(): void
    {
        $store = new TuiStateStore;
        $store->setTokensIn(5_000);

        $this->assertSame(0.0, $store->getContextPercent());
    }

    public function test_context_percent_reacts_to_changes(): void
    {
        $store = new TuiStateStore;
        $store->setMaxContext(100_000);
        $store->setTokensIn(25_000);

        $this->assertSame(25.0, $store->getContextPercent());

        $store->setTokensIn(75_000);
        $this->assertSame(75.0, $store->getContextPercent());

        // Tokens exceed max → can go over 100%
        $store->setMaxContext(50_000);
        $this->assertSame(150.0, $store->getContextPercent());
    }

    // ── Signal accessors ────────────────────────────────────────────────

    public function test_mode_label_signal(): void
    {
        $store = new TuiStateStore;
        $signal = $store->modeLabelSignal();

        $captured = null;
        $signal->subscribe(function (string $v) use (&$captured): void {
            $captured = $v;
        });

        $store->setModeLabel('Plan');
        $this->assertSame('Plan', $captured);
    }

    public function test_phase_signal_fires_on_change(): void
    {
        $store = new TuiStateStore;
        $changes = [];
        $store->phaseSignal()->subscribe(function (string $v) use (&$changes): void {
            $changes[] = $v;
        });

        $store->setPhase('thinking');
        $store->setPhase('tools');

        $this->assertSame(['thinking', 'tools'], $changes);
    }

    public function test_scroll_offset_signal_fires_on_change(): void
    {
        $store = new TuiStateStore;
        $captured = null;
        $store->scrollOffsetSignal()->subscribe(function (int $v) use (&$captured): void {
            $captured = $v;
        });

        $store->setScrollOffset(42);
        $this->assertSame(42, $captured);
    }

    // ── Batch helper ────────────────────────────────────────────────────

    public function test_batch_groups_updates(): void
    {
        $store = new TuiStateStore;

        $phaseChanges = [];
        $store->phaseSignal()->subscribe(function (string $v) use (&$phaseChanges): void {
            $phaseChanges[] = $v;
        });

        $store->batch(function (TuiStateStore $s): void {
            $s->setPhase('thinking');
            $s->setModeLabel('Plan');
        });

        // Inside batch, subscribers are deferred — only the final value is notified
        $this->assertSame('thinking', end($phaseChanges));
    }

    // ── Computed: isBrowsingHistory ──────────────────────────────────────

    public function test_is_browsing_history(): void
    {
        $store = new TuiStateStore;
        $this->assertFalse($store->getIsBrowsingHistory());

        $store->setScrollOffset(5);
        $this->assertTrue($store->getIsBrowsingHistory());

        $store->setScrollOffset(0);
        $this->assertFalse($store->getIsBrowsingHistory());
    }

    // ── Computed: statusBarMessage ──────────────────────────────────────

    public function test_status_bar_message_computed(): void
    {
        $store = new TuiStateStore;
        $msg = $store->getStatusBarMessage();

        // Should contain both labels and the detail
        $this->assertStringContainsString('Edit', $msg);
        $this->assertStringContainsString('Guardian ◈', $msg);
        $this->assertStringContainsString('Ready', $msg);

        $store->setModeLabel('Plan');
        $store->setStatusDetail('Processing...');
        $updated = $store->getStatusBarMessage();
        $this->assertStringContainsString('Plan', $updated);
        $this->assertStringContainsString('Processing...', $updated);
    }

    // ── Computed reactivity with Effects ────────────────────────────────

    public function test_effect_fires_on_status_bar_signals(): void
    {
        $store = new TuiStateStore;
        $captured = [];

        $effect = new Effect(function () use ($store, &$captured): void {
            $captured[] = $store->getStatusBarMessage();
        });

        // Initial run
        $this->assertCount(1, $captured);

        // Changing mode label triggers effect
        $store->setModeLabel('Plan');
        $this->assertCount(2, $captured);
        $this->assertStringContainsString('Plan', $captured[1]);

        $effect->dispose();
    }

    public function test_effect_fires_on_scroll_offset_change(): void
    {
        $store = new TuiStateStore;
        $browsing = [];

        $effect = new Effect(function () use ($store, &$browsing): void {
            $browsing[] = $store->getIsBrowsingHistory();
        });

        $this->assertCount(1, $browsing);
        $this->assertFalse($browsing[0]);

        $store->setScrollOffset(10);
        $this->assertCount(2, $browsing);
        $this->assertTrue($browsing[1]);

        $store->setScrollOffset(0);
        $this->assertCount(3, $browsing);
        $this->assertFalse($browsing[2]);

        $effect->dispose();
    }

    public function test_effect_tracks_multiple_signals(): void
    {
        $store = new TuiStateStore;
        $renderCount = 0;

        $effect = new Effect(function () use ($store, &$renderCount): void {
            $store->modeLabelSignal()->get();
            $store->statusDetailSignal()->get();
            $renderCount++;
        });

        $this->assertSame(1, $renderCount);

        $store->setModeLabel('Plan');
        $this->assertSame(2, $renderCount);

        $store->setStatusDetail('Working...');
        $this->assertSame(3, $renderCount);

        $effect->dispose();
    }

    public function test_batch_defers_effects(): void
    {
        $store = new TuiStateStore;
        $renderCount = 0;

        $effect = new Effect(function () use ($store, &$renderCount): void {
            $store->modeLabelSignal()->get();
            $store->statusDetailSignal()->get();
            $renderCount++;
        });

        $this->assertSame(1, $renderCount);

        // Without batch: two sets = two effect runs = 3 total
        // With batch: effects deferred, then fired once per changed signal = fewer runs
        BatchScope::run(function () use ($store): void {
            $store->setModeLabel('Plan');
            $store->setStatusDetail('Working...');
        });

        // Batch flush fires signal subscribers, which each trigger the effect.
        // The effect runs once per dependency that changed (modeLabel and statusDetail).
        $this->assertSame(3, $renderCount);

        // Compare with unbatched: would also be 3 (1 + 1 + 1).
        // The key benefit of batching is that widget updates are synchronized
        // within the effect execution, not that effects run fewer times.

        $effect->dispose();
    }

    public function test_disposed_effect_does_not_fire(): void
    {
        $store = new TuiStateStore;
        $renderCount = 0;

        $effect = new Effect(function () use ($store, &$renderCount): void {
            $store->modeLabelSignal()->get();
            $renderCount++;
        });

        $this->assertSame(1, $renderCount);
        $effect->dispose();

        $store->setModeLabel('Plan');
        $this->assertSame(1, $renderCount); // No change after dispose
    }

    // ── Computed accessors return same instance ─────────────────────────

    public function test_context_percent_computed_returns_same_instance(): void
    {
        $store = new TuiStateStore;
        $c1 = $store->contextPercentComputed();
        $c2 = $store->contextPercentComputed();
        $this->assertSame($c1, $c2);
    }

    public function test_is_browsing_history_computed_returns_same_instance(): void
    {
        $store = new TuiStateStore;
        $c1 = $store->isBrowsingHistoryComputed();
        $c2 = $store->isBrowsingHistoryComputed();
        $this->assertSame($c1, $c2);
    }

    public function test_status_bar_message_computed_returns_same_instance(): void
    {
        $store = new TuiStateStore;
        $c1 = $store->statusBarMessageComputed();
        $c2 = $store->statusBarMessageComputed();
        $this->assertSame($c1, $c2);
    }

    // ── Session / error state ───────────────────────────────────────────

    public function test_session_title_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame('', $store->getSessionTitle());
        $store->setSessionTitle('My Session');
        $this->assertSame('My Session', $store->getSessionTitle());
    }

    public function test_error_count_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0, $store->getErrorCount());
        $store->setErrorCount(3);
        $this->assertSame(3, $store->getErrorCount());
    }

    // ── Discovery items ─────────────────────────────────────────────────

    public function test_active_discovery_items_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame([], $store->getActiveDiscoveryItems());
        $items = [['name' => 'file_read', 'status' => 'pending']];
        $store->setActiveDiscoveryItems($items);
        $this->assertSame($items, $store->getActiveDiscoveryItems());
    }

    // ── Start time ──────────────────────────────────────────────────────

    public function test_start_time_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0.0, $store->getStartTime());
        $now = microtime(true);
        $store->setStartTime($now);
        $this->assertSame($now, $store->getStartTime());
    }

    // ── Compacting state ────────────────────────────────────────────────

    public function test_compacting_start_time_round_trip(): void
    {
        $store = new TuiStateStore;
        $this->assertSame(0.0, $store->getCompactingStartTime());
        $now = microtime(true);
        $store->setCompactingStartTime($now);
        $this->assertSame($now, $store->getCompactingStartTime());
    }
}
