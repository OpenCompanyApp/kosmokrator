<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ReactiveWidgetTest extends TestCase
{
    /**
     * Concrete test implementation that tracks whether syncFromSignals reports changes.
     */
    private function createSyncCountingWidget(): object
    {
        return new class extends ReactiveWidget
        {
            public int $syncCount = 0;

            private bool $changed = false;

            public function markChanged(): void
            {
                $this->changed = true;
            }

            public function syncFromSignals(): bool
            {
                $this->syncCount++;

                return $this->changed;
            }

            public function render(RenderContext $context): array
            {
                return [];
            }
        };
    }

    public function test_before_render_calls_sync_from_signals(): void
    {
        $widget = $this->createSyncCountingWidget();

        $this->assertSame(0, $widget->syncCount);
        $widget->beforeRender();
        $this->assertSame(1, $widget->syncCount);
    }

    public function test_before_render_calls_sync_every_frame(): void
    {
        $widget = $this->createSyncCountingWidget();

        $widget->beforeRender();
        $widget->beforeRender();
        $widget->beforeRender();

        $this->assertSame(3, $widget->syncCount);
    }
}
