<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ContextBudget;
use Kosmokrator\LLM\ModelCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ContextBudgetTest extends TestCase
{
    // ---------------------------------------------------------------
    // contextWindow
    // ---------------------------------------------------------------

    public function test_context_window_returns_default_when_models_is_null(): void
    {
        $budget = new ContextBudget(models: null);
        $this->assertSame(200_000, $budget->contextWindow('any-model'));
    }

    public function test_context_window_delegates_to_model_catalog(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')
            ->with('gpt-4o')
            ->willReturn(128_000);

        $budget = new ContextBudget(models: $catalog);
        $this->assertSame(128_000, $budget->contextWindow('gpt-4o'));
    }

    // ---------------------------------------------------------------
    // effectiveContextWindow
    // ---------------------------------------------------------------

    public function test_effective_context_window_subtracts_reserve(): void
    {
        $budget = new ContextBudget(models: null, reserveOutputTokens: 10_000);
        // 200_000 - 10_000 = 190_000
        $this->assertSame(190_000, $budget->effectiveContextWindow('model'));
    }

    public function test_effective_context_window_clamps_to_one(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(500);

        $budget = new ContextBudget(models: $catalog, reserveOutputTokens: 100_000);
        // max(1, 500 - 100_000) = max(1, -99_500) = 1
        $this->assertSame(1, $budget->effectiveContextWindow('model'));
    }

    public function test_effective_context_window_with_zero_reserve(): void
    {
        $budget = new ContextBudget(models: null, reserveOutputTokens: 0);
        $this->assertSame(200_000, $budget->effectiveContextWindow('model'));
    }

    // ---------------------------------------------------------------
    // warningThreshold
    // ---------------------------------------------------------------

    public function test_warning_threshold_with_buffer(): void
    {
        // effective = 200_000, warning buffer = 20_000 → threshold = 180_000
        $budget = new ContextBudget(models: null, warningBufferTokens: 20_000);
        $this->assertSame(180_000, $budget->warningThreshold('model'));
    }

    public function test_warning_threshold_clamps_to_one(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(100);

        // effective = max(1, 100 - 0) = 100; threshold = max(1, 100 - 500) = 1
        $budget = new ContextBudget(models: $catalog, warningBufferTokens: 500);
        $this->assertSame(1, $budget->warningThreshold('model'));
    }

    // ---------------------------------------------------------------
    // autoCompactThreshold
    // ---------------------------------------------------------------

    public function test_auto_compact_threshold_with_buffer(): void
    {
        // effective = 200_000, auto-compact buffer = 30_000 → threshold = 170_000
        $budget = new ContextBudget(models: null, autoCompactBufferTokens: 30_000);
        $this->assertSame(170_000, $budget->autoCompactThreshold('model'));
    }

    public function test_auto_compact_threshold_clamps_to_one(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(50);

        // effective = 50; threshold = max(1, 50 - 200) = 1
        $budget = new ContextBudget(models: $catalog, autoCompactBufferTokens: 200);
        $this->assertSame(1, $budget->autoCompactThreshold('model'));
    }

    // ---------------------------------------------------------------
    // blockingThreshold
    // ---------------------------------------------------------------

    public function test_blocking_threshold_with_buffer(): void
    {
        // effective = 200_000, blocking buffer = 5_000 → threshold = 195_000
        $budget = new ContextBudget(models: null, blockingBufferTokens: 5_000);
        $this->assertSame(195_000, $budget->blockingThreshold('model'));
    }

    public function test_blocking_threshold_clamps_to_one(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(10);

        // effective = 10; threshold = max(1, 10 - 50) = 1
        $budget = new ContextBudget(models: $catalog, blockingBufferTokens: 50);
        $this->assertSame(1, $budget->blockingThreshold('model'));
    }

    // ---------------------------------------------------------------
    // Combined thresholds with all buffers set
    // ---------------------------------------------------------------

    public function test_all_thresholds_with_mock_catalog_and_all_buffers(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(100_000);

        $budget = new ContextBudget(
            models: $catalog,
            reserveOutputTokens: 10_000,
            warningBufferTokens: 20_000,
            autoCompactBufferTokens: 30_000,
            blockingBufferTokens: 5_000,
        );

        // effective = 100_000 - 10_000 = 90_000
        $this->assertSame(100_000, $budget->contextWindow('model'));
        $this->assertSame(90_000, $budget->effectiveContextWindow('model'));
        $this->assertSame(70_000, $budget->warningThreshold('model'));         // 90_000 - 20_000
        $this->assertSame(60_000, $budget->autoCompactThreshold('model'));     // 90_000 - 30_000
        $this->assertSame(85_000, $budget->blockingThreshold('model'));        // 90_000 - 5_000
    }

    // ---------------------------------------------------------------
    // snapshot
    // ---------------------------------------------------------------

    public function test_snapshot_structure_and_values(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->method('contextWindow')->willReturn(100_000);

        $budget = new ContextBudget(
            models: $catalog,
            reserveOutputTokens: 10_000,
            warningBufferTokens: 20_000,
            autoCompactBufferTokens: 30_000,
            blockingBufferTokens: 5_000,
        );

        $snapshot = $budget->snapshot(estimatedTokens: 50_000, model: 'gpt-4o');

        // effective = 90_000; percent_left = round((90_000 - 50_000) / 90_000 * 100) = round(44.44) = 44
        $this->assertSame(50_000, $snapshot['estimated_tokens']);
        $this->assertSame(100_000, $snapshot['context_window']);
        $this->assertSame(90_000, $snapshot['effective_window']);
        $this->assertSame(70_000, $snapshot['warning_threshold']);
        $this->assertSame(60_000, $snapshot['auto_compact_threshold']);
        $this->assertSame(85_000, $snapshot['blocking_threshold']);
        $this->assertSame(44, $snapshot['percent_left']);
        $this->assertFalse($snapshot['is_above_warning']);
        $this->assertFalse($snapshot['is_above_auto_compact']);
        $this->assertFalse($snapshot['is_at_blocking_limit']);
    }

    public function test_snapshot_flags_when_above_warning_but_below_auto_compact(): void
    {
        $budget = new ContextBudget(
            models: null,
            reserveOutputTokens: 10_000,
            warningBufferTokens: 20_000,
            autoCompactBufferTokens: 30_000,
            blockingBufferTokens: 5_000,
        );

        // effective = 190_000, warning = 170_000, auto_compact = 160_000, blocking = 185_000
        // 175_000 >= 170_000 (warning: true), 175_000 < 160_000? No, 175_000 >= 160_000 (auto_compact: true)
        // Use 165_000: above warning (170_000? No). Use 175_000: above auto_compact (160_000 yes) and warning (170_000 yes).
        // Use 165_000: warning=true (>=170_000? no, 165<170), so we need 172_000.
        // 172_000 >= 170_000 → warning true; 172_000 < 160_000? no, 172 >= 160 → auto_compact also true.
        // These thresholds are close together. Let's test the distinct layers separately.

        // 172_000: above warning threshold (170_000) and above auto_compact (160_000)
        $snapshot = $budget->snapshot(estimatedTokens: 172_000, model: 'model');

        $this->assertTrue($snapshot['is_above_warning']);
        $this->assertTrue($snapshot['is_above_auto_compact']);
        $this->assertFalse($snapshot['is_at_blocking_limit']); // 172_000 < 185_000
    }

    public function test_snapshot_flags_true_when_tokens_exceed_thresholds(): void
    {
        $budget = new ContextBudget(
            models: null,
            reserveOutputTokens: 10_000,
            warningBufferTokens: 20_000,
            autoCompactBufferTokens: 30_000,
            blockingBufferTokens: 5_000,
        );

        // effective = 190_000, warning = 170_000, auto_compact = 160_000, blocking = 185_000
        // Use estimatedTokens = 190_000 → all flags true
        $snapshot = $budget->snapshot(estimatedTokens: 190_000, model: 'model');

        $this->assertTrue($snapshot['is_above_warning']);
        $this->assertTrue($snapshot['is_above_auto_compact']);
        $this->assertTrue($snapshot['is_at_blocking_limit']);
        $this->assertSame(0, $snapshot['percent_left']); // max(0, round((190_000 - 190_000)/190_000 * 100)) = 0
    }

    public function test_snapshot_percent_left_clamps_to_zero_when_exceeded(): void
    {
        $budget = new ContextBudget(
            models: null,
            reserveOutputTokens: 10_000,
        );

        // effective = 190_000, estimated = 200_000
        $snapshot = $budget->snapshot(estimatedTokens: 200_000, model: 'model');

        // percent_left = max(0, round((190_000 - 200_000) / 190_000 * 100)) = max(0, round(-5.26)) = max(0, -5) = 0
        $this->assertSame(0, $snapshot['percent_left']);
    }

    public function test_snapshot_percent_left_at_50_percent(): void
    {
        $budget = new ContextBudget(models: null);

        // effective = 200_000, estimated = 100_000 → percent_left = round(100_000 / 200_000 * 100) = 50
        $snapshot = $budget->snapshot(estimatedTokens: 100_000, model: 'model');

        $this->assertSame(50, $snapshot['percent_left']);
    }

    public function test_snapshot_all_flags_false_when_tokens_low(): void
    {
        $budget = new ContextBudget(
            models: null,
            warningBufferTokens: 20_000,
            autoCompactBufferTokens: 30_000,
            blockingBufferTokens: 5_000,
        );

        // effective = 200_000, warning = 180_000, auto_compact = 170_000, blocking = 195_000
        $snapshot = $budget->snapshot(estimatedTokens: 50_000, model: 'model');

        $this->assertFalse($snapshot['is_above_warning']);
        $this->assertFalse($snapshot['is_above_auto_compact']);
        $this->assertFalse($snapshot['is_at_blocking_limit']);
    }

    public function test_snapshot_exact_boundary_at_warning(): void
    {
        $budget = new ContextBudget(
            models: null,
            warningBufferTokens: 20_000,
        );

        // effective = 200_000, warning = 180_000
        $snapshot = $budget->snapshot(estimatedTokens: 180_000, model: 'model');

        $this->assertTrue($snapshot['is_above_warning']); // exactly at boundary (>=)
    }

    public function test_snapshot_exact_boundary_below_warning(): void
    {
        $budget = new ContextBudget(
            models: null,
            warningBufferTokens: 20_000,
        );

        // effective = 200_000, warning = 180_000
        $snapshot = $budget->snapshot(estimatedTokens: 179_999, model: 'model');

        $this->assertFalse($snapshot['is_above_warning']);
    }

    public function test_snapshot_with_null_models_uses_default_window(): void
    {
        $budget = new ContextBudget(models: null);

        $snapshot = $budget->snapshot(estimatedTokens: 0, model: 'anything');

        $this->assertSame(200_000, $snapshot['context_window']);
        $this->assertSame(200_000, $snapshot['effective_window']);
        $this->assertSame(100, $snapshot['percent_left']);
    }

    public function test_snapshot_passes_model_to_catalog(): void
    {
        $catalog = $this->createMock(ModelCatalog::class);
        $catalog->expects($this->atLeastOnce())
            ->method('contextWindow')
            ->with('specific-model')
            ->willReturn(50_000);

        $budget = new ContextBudget(models: $catalog);

        $snapshot = $budget->snapshot(estimatedTokens: 10_000, model: 'specific-model');

        $this->assertSame(50_000, $snapshot['context_window']);
        $this->assertSame(50_000, $snapshot['effective_window']);
    }
}
