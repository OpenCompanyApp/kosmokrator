<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Builder;

use Kosmokrator\UI\Tui\Builder\StatusBarBuilder;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;

final class StatusBarBuilderTest extends TestCase
{
    private TuiStateStore $state;

    private StatusBarBuilder $builder;

    protected function setUp(): void
    {
        $this->state = new TuiStateStore;
        $this->builder = StatusBarBuilder::create($this->state);
    }

    public function test_create_produces_widget_with_id(): void
    {
        $this->assertSame('status-bar', $this->builder->getWidget()->getId());
    }

    public function test_update_reads_computed_message(): void
    {
        $this->state->setModeLabel('Plan');
        $this->builder->update();

        $text = $this->builder->getWidget()->getMessage();
        $this->assertStringContainsString('Plan', $text);
    }

    public function test_format_token_detail_sets_status_detail(): void
    {
        $result = $this->builder->formatTokenDetail('claude-sonnet', 50_000, 200_000);

        $this->assertStringContainsString('claude-sonnet', $result);
        $this->assertStringContainsString('50k', $result);
        $this->assertStringContainsString('200k', $result);
        $this->assertSame($result, $this->state->getStatusDetail());
    }

    public function test_format_runtime_detail_without_max_context(): void
    {
        $this->state->setMaxContext(null);
        $result = $this->builder->formatRuntimeDetail('anthropic', 'claude-4', 0, 200_000);

        $this->assertStringContainsString('anthropic/claude-4', $result);
        $this->assertSame($result, $this->state->getStatusDetail());
    }

    public function test_format_runtime_detail_with_max_context(): void
    {
        $this->state->setMaxContext(200_000);
        $result = $this->builder->formatRuntimeDetail('anthropic', 'claude-4', 50_000, 200_000);

        $this->assertStringContainsString('anthropic/claude-4', $result);
        $this->assertStringContainsString('50k', $result);
    }

    public function test_update_progress_sets_bar_position(): void
    {
        $widget = $this->builder->getWidget();
        $this->builder->updateProgress(50_000, 200_000);

        $this->assertSame(200_000, $widget->getMaxSteps());
        $this->assertSame(50_000, $widget->getProgress());
    }
}
