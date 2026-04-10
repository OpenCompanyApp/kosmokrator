<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Composition;

use Kosmokrator\UI\Tui\Composition\CompactingLoaderWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class CompactingLoaderWidgetTest extends TestCase
{
    public function test_sync_from_signals_updates_message_when_compacting_breath_tick_changes(): void
    {
        $state = new TuiStateStore;
        $state->setHasCompactingLoader(true);
        $state->setCompactingBreathTick(0);

        $widget = new CompactingLoaderWidget($state);

        $this->assertTrue($widget->syncFromSignals());
        $phrase = $state->getThinkingPhrase();
        $this->assertNotNull($phrase);

        $first = implode("\n", $widget->render(new RenderContext(120, 2)));
        $this->assertStringContainsString($phrase, $first);
        $this->assertStringContainsString('(00:00)', $first);

        $state->setCompactingBreathTick(6);

        $this->assertTrue($widget->syncFromSignals());
        $second = implode("\n", $widget->render(new RenderContext(120, 2)));

        $this->assertNotSame($first, $second);
    }
}
