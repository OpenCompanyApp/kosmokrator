<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\SwarmDashboardWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * @psalm-suppress PossiblyNullFunctionCall
 */
final class SwarmDashboardWidgetTest extends TestCase
{
    private function makeSummary(array $overrides = []): array
    {
        return array_merge([
            'total' => 10,
            'done' => 5,
            'running' => 3,
            'queued' => 1,
            'failed' => 1,
            'retrying' => 0,
            'tokensIn' => 50000,
            'tokensOut' => 25000,
            'cost' => 0.75,
            'avgCost' => 0.15,
            'elapsed' => 120,
            'rate' => 2.5,
            'eta' => 180,
            'active' => [],
            'failures' => [],
            'retriedAndRecovered' => 0,
            'byType' => [],
        ], $overrides);
    }

    public function test_constructor_sets_data(): void
    {
        $summary = $this->makeSummary();
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
    }

    public function test_render_produces_bordered_output(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('┌', $lines[0]);
        $lastLine = $lines[count($lines) - 1];
        $this->assertStringContainsString('┘', $lastLine);
    }

    public function test_render_shows_swarm_control_title(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('S W A R M', $content);
    }

    public function test_render_shows_offline_snapshot_marker(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(['stale' => true]), []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('offline snapshot', $content);
    }

    public function test_render_shows_progress_bar(): void
    {
        $summary = $this->makeSummary(['total' => 10, 'done' => 5]);
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('50.0%', $content);
        $this->assertStringContainsString('5 of 10 agents completed', $content);
    }

    public function test_render_shows_status_counts(): void
    {
        $summary = $this->makeSummary([
            'done' => 5,
            'running' => 3,
            'queued' => 1,
            'failed' => 2,
        ]);
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('5 done', $content);
        $this->assertStringContainsString('3 running', $content);
        $this->assertStringContainsString('1 queued', $content);
        $this->assertStringContainsString('2 failed', $content);
    }

    public function test_render_shows_resources_section(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Tokens', $content);
        $this->assertStringContainsString('Cost', $content);
        $this->assertStringContainsString('Elapsed', $content);
    }

    public function test_render_shows_eta_when_positive(): void
    {
        $summary = $this->makeSummary(['eta' => 180]);
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('ETA', $content);
        $this->assertStringContainsString('remaining', $content);
    }

    public function test_render_hides_eta_when_zero(): void
    {
        $summary = $this->makeSummary(['eta' => 0]);
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString('remaining', $content);
    }

    public function test_render_shows_footer_hint(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Esc/q close', $content);
    }

    public function test_set_data_updates_summary(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $newSummary = $this->makeSummary(['done' => 8, 'total' => 10]);
        $widget->setData($newSummary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('8 of 10 agents completed', $content);
    }

    public function test_on_dismiss_callback_invoked_on_escape(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput("\x1b"); // Escape

        $this->assertTrue($called);
    }

    public function test_on_dismiss_callback_invoked_on_q(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput('q');

        $this->assertTrue($called);
    }

    public function test_on_dismiss_returns_static_for_chaining(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);

        $result = $widget->onDismiss(static fn (): bool => true);

        $this->assertSame($widget, $result);
    }

    public function test_handle_input_ignores_unknown_keys(): void
    {
        $widget = new SwarmDashboardWidget($this->makeSummary(), []);
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput('a');

        $this->assertFalse($called, 'Dismiss should not be called for unknown key');
    }

    public function test_render_shows_active_section_when_running(): void
    {
        $agent = new class
        {
            public string $status = 'running';

            public string $agentType = 'general';

            public string $task = 'Explore codebase';

            public int $toolCalls = 5;

            public ?string $lastTool = 'grep';

            public ?float $nextRetryAt = null;

            public function elapsed(): float
            {
                return 45.0;
            }
        };

        $summary = $this->makeSummary([
            'running' => 1,
            'active' => [$agent],
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Active', $content);
        $this->assertStringContainsString('Explore codebase', $content);
        $this->assertStringContainsString('last grep', $content);
    }

    public function test_render_shows_retry_countdown_for_retrying_agent(): void
    {
        $agent = new class
        {
            public string $status = 'retrying';

            public string $agentType = 'explore';

            public string $task = 'Retry provider request';

            public int $toolCalls = 2;

            public ?string $lastTool = null;

            public ?float $nextRetryAt;

            public function __construct()
            {
                $this->nextRetryAt = microtime(true) + 30.0;
            }

            public function elapsed(): float
            {
                return 12.0;
            }
        };

        $summary = $this->makeSummary([
            'retrying' => 1,
            'active' => [$agent],
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('retry in', $content);
    }

    public function test_render_shows_failures_section(): void
    {
        $agent = new class
        {
            public string $status = 'failed';

            public string $agentType = 'general';

            public string $task = 'Broken task';

            public ?string $error = 'timeout exceeded';
        };

        $summary = $this->makeSummary([
            'failed' => 1,
            'failures' => [$agent],
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Failures', $content);
        $this->assertStringContainsString('Broken task', $content);
    }

    public function test_render_shows_recovered_count(): void
    {
        $summary = $this->makeSummary([
            'failed' => 3,
            'retriedAndRecovered' => 1,
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('1 recovered via retry', $content);
        $this->assertStringContainsString('2 permanent', $content);
    }

    public function test_render_shows_by_type_section_when_multiple_types(): void
    {
        $summary = $this->makeSummary([
            'byType' => [
                'general' => ['done' => 3, 'running' => 1, 'queued' => 0, 'tokensIn' => 30000, 'tokensOut' => 15000],
                'explore' => ['done' => 2, 'running' => 0, 'queued' => 1, 'tokensIn' => 20000, 'tokensOut' => 10000],
            ],
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('By Type', $content);
        $this->assertStringContainsString('General', $content);
        $this->assertStringContainsString('Explore', $content);
    }

    public function test_render_hides_by_type_section_when_single_type(): void
    {
        $summary = $this->makeSummary([
            'byType' => [
                'general' => ['done' => 5, 'running' => 0, 'queued' => 0],
            ],
        ]);

        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString('By Type', $content);
    }

    public function test_render_handles_zero_total(): void
    {
        $summary = $this->makeSummary(['total' => 0, 'done' => 0]);
        $widget = new SwarmDashboardWidget($summary, []);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('0.0%', $content);
        $this->assertStringContainsString('0 of 0 agents completed', $content);
    }
}
