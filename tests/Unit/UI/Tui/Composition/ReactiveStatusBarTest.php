<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Composition;

use Kosmokrator\UI\Tui\Composition\ReactiveStatusBar;
use Kosmokrator\UI\Tui\Composition\StatusBar;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ReactiveStatusBarTest extends TestCase
{
    private TuiStateStore $state;

    private ReactiveStatusBar $bar;

    protected function setUp(): void
    {
        $this->state = new TuiStateStore;
        $this->bar = ReactiveStatusBar::create($this->state);
    }

    public function test_create_returns_instance(): void
    {
        $this->assertNotNull($this->bar);
    }

    public function test_get_bar_returns_progress_widget(): void
    {
        $this->assertNotNull($this->bar->getBar());
    }

    public function test_sync_returns_true_on_first_call(): void
    {
        $this->assertTrue($this->bar->syncFromSignals());
    }

    public function test_sync_returns_false_when_no_change(): void
    {
        $this->bar->syncFromSignals();
        $this->assertFalse($this->bar->syncFromSignals());
    }

    public function test_sync_detects_message_change(): void
    {
        $this->bar->syncFromSignals();

        // Change the status bar message via signal
        StatusBar::formatTokenDetail($this->state, 'gpt-4', 100, 8000);

        $this->assertTrue($this->bar->syncFromSignals());
    }

    public function test_sync_detects_tokens_change(): void
    {
        $this->bar->syncFromSignals();

        $this->state->setTokensIn(500);

        $this->assertTrue($this->bar->syncFromSignals());
    }

    public function test_sync_detects_max_context_change(): void
    {
        $this->bar->syncFromSignals();

        $this->state->setMaxContext(16000);

        $this->assertTrue($this->bar->syncFromSignals());
    }

    public function test_render_delegates_to_bar(): void
    {
        $this->state->setMaxContext(8000);
        $this->state->setTokensIn(100);
        $this->bar->syncFromSignals();

        $context = new RenderContext(80, 24);
        $result = $this->bar->render($context);

        // Should delegate to the ProgressBarWidget render
        $this->assertIsArray($result);
    }
}
