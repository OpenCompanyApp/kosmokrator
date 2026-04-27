<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\KosmokratorStyleSheet;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\TuiEventBridge;
use Kosmokrator\UI\Tui\TuiScheduler;
use Kosmokrator\UI\Tui\Widget\KosmokratorEditorWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\ScreenBufferHtmlRenderer;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\TeeTerminal;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

final class TuiFrameworkIntegrationTest extends TestCase
{
    public function test_virtual_terminal_renders_and_exports_html_snapshot(): void
    {
        $terminal = new VirtualTerminal(80, 16);
        $tui = new Tui(KosmokratorStyleSheet::create(), terminal: $terminal);
        $widget = new TextWidget('Kosmo snapshot');
        $widget->addStyleClass('welcome');

        try {
            $tui->add($widget);
            $tui->start();
            $tui->tick();

            $screen = new ScreenBuffer(80, 16);
            $screen->write($terminal->getOutput());

            $this->assertStringContainsString('Kosmo snapshot', $screen->getScreen());
            $this->assertStringContainsString('Kosmo snapshot', (new ScreenBufferHtmlRenderer)->convert($screen));
        } finally {
            $tui->stop();
        }
    }

    public function test_scheduled_animation_does_not_block_editor_input(): void
    {
        $terminal = new VirtualTerminal(80, 16);
        $tui = new Tui(KosmokratorStyleSheet::create(), terminal: $terminal);
        $scheduler = TuiScheduler::fromTui($tui);
        $input = new KosmokratorEditorWidget;
        $input->setId('prompt');
        $ticks = 0;

        try {
            $tui->add($input);
            $tui->setFocus($input);
            $scheduler->every(0.001, function () use (&$ticks): void {
                $ticks++;
            });

            $tui->start();
            $terminal->simulateInput('h');
            $terminal->simulateInput('i');
            usleep(2000);
            $tui->tick();

            $this->assertSame('hi', $input->getText());
            $this->assertGreaterThan(0, $ticks);
        } finally {
            $tui->stop();
        }
    }

    public function test_global_event_bridge_tracks_focus_and_handles_force_render(): void
    {
        $terminal = new VirtualTerminal(80, 16);
        $tui = new Tui(KosmokratorStyleSheet::create(), terminal: $terminal);
        $state = new TuiStateStore;
        $input = new KosmokratorEditorWidget;
        $input->setId('prompt');
        $forceRenders = 0;
        $bridge = new TuiEventBridge($tui, $state, function () use (&$forceRenders): void {
            $forceRenders++;
        });

        try {
            $bridge->bind();
            $tui->add($input);
            $tui->setFocus($input);
            $tui->start();

            $terminal->simulateInput("\x0C");

            $this->assertSame('prompt', $state->getFocusedWidgetId());
            $this->assertSame(1, $forceRenders);
            $this->assertSame('', $input->getText());
        } finally {
            $tui->stop();
        }
    }

    public function test_tee_terminal_records_the_same_rendered_output(): void
    {
        $primary = new VirtualTerminal(80, 16);
        $recording = new VirtualTerminal(80, 16);
        $tui = new Tui(KosmokratorStyleSheet::create(), terminal: new TeeTerminal($primary, $recording));

        try {
            $tui->add(new TextWidget('Recorded frame'));
            $tui->start();
            $tui->tick();

            $primaryScreen = new ScreenBuffer(80, 16);
            $primaryScreen->write($primary->getOutput());

            $recordedScreen = new ScreenBuffer(80, 16);
            $recordedScreen->write($recording->getOutput());

            $this->assertSame($primaryScreen->getScreen(), $recordedScreen->getScreen());
            $this->assertStringContainsString('Recorded frame', $recordedScreen->getScreen());
        } finally {
            $tui->stop();
        }
    }
}
