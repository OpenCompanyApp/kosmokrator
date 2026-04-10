<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Composition;

use Kosmokrator\UI\Tui\Composition\ThinkingLoaderWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ThinkingLoaderWidgetTest extends TestCase
{
    public function test_sync_from_signals_updates_rendered_message_when_subagent_activity_changes(): void
    {
        $state = new TuiStateStore;
        $state->setHasThinkingLoader(true);
        $state->setThinkingStartTime(microtime(true) - 9);
        $state->setBreathColor("\033[38;2;112;160;208m");
        $state->setHasSubagentActivity(false);

        $widget = new ThinkingLoaderWidget($state);

        $this->assertTrue($widget->syncFromSignals());
        $phrase = $state->getThinkingPhrase();
        $this->assertNotNull($phrase);

        $withElapsed = implode("\n", $widget->render(new RenderContext(120, 2)));
        $this->assertStringContainsString($phrase, $withElapsed);
        $this->assertStringContainsString('0:09', $withElapsed);

        $state->setHasSubagentActivity(true);

        $this->assertTrue($widget->syncFromSignals());
        $withoutElapsed = implode("\n", $widget->render(new RenderContext(120, 2)));
        $this->assertStringContainsString($phrase, $withoutElapsed);
        $this->assertStringNotContainsString('0:09', $withoutElapsed);
    }

    public function test_sync_from_signals_updates_rendered_message_when_breath_color_changes(): void
    {
        $state = new TuiStateStore;
        $state->setHasThinkingLoader(true);
        $state->setThinkingStartTime(microtime(true) - 9);
        $state->setBreathColor("\033[38;2;112;160;208m");

        $widget = new ThinkingLoaderWidget($state);
        $widget->syncFromSignals();

        $context = new RenderContext(120, 2);
        $first = implode("\n", $widget->render($context));

        $state->setBreathColor("\033[38;2;152;200;248m");

        $this->assertTrue($widget->syncFromSignals());
        $second = implode("\n", $widget->render($context));

        $this->assertNotSame($first, $second);
    }
}
