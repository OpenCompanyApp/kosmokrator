<?php

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Ansi\AnsiRenderer;
use Kosmokrator\UI\TerminalNotification;
use PHPUnit\Framework\TestCase;

class TerminalNotificationTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];

    private string $originalTermProgram;

    protected function setUp(): void
    {
        $this->originalTermProgram = getenv('TERM_PROGRAM') ?: '';
        $this->captured = [];
        TerminalNotification::setWriter(function (string $data): void {
            $this->captured[] = $data;
        });
    }

    protected function tearDown(): void
    {
        putenv("TERM_PROGRAM={$this->originalTermProgram}");
        TerminalNotification::setWriter(null);
    }

    // ---------------------------------------------------------------
    // Core: BEL always emitted
    // ---------------------------------------------------------------

    public function test_bell_always_emitted(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');

        TerminalNotification::notify();

        $output = implode('', $this->captured);
        $this->assertStringContainsString("\x07", $output, 'BEL should always be emitted');
    }

    public function test_bell_is_first_byte(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');

        TerminalNotification::notify();

        $this->assertNotEmpty($this->captured);
        $this->assertSame("\x07", $this->captured[0], 'BEL should be the first thing written');
    }

    // ---------------------------------------------------------------
    // iTerm2: OSC 9
    // ---------------------------------------------------------------

    public function test_iterm2_osc_emitted(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');

        TerminalNotification::notify();

        $output = implode('', $this->captured);
        // Should have BEL + OSC 9
        $this->assertStringContainsString("\x07", $output);
        $this->assertStringContainsString(']9;KosmoKrator', $output);
    }

    // ---------------------------------------------------------------
    // Ghostty: OSC 777
    // ---------------------------------------------------------------

    public function test_ghostty_osc_emitted(): void
    {
        putenv('TERM_PROGRAM=ghostty');

        TerminalNotification::notify();

        $output = implode('', $this->captured);
        $this->assertStringContainsString("\x07", $output);
        $this->assertStringContainsString(']777;notify;KosmoKrator', $output);
    }

    // ---------------------------------------------------------------
    // Kitty: OSC 99
    // ---------------------------------------------------------------

    public function test_kitty_osc_emitted(): void
    {
        putenv('TERM_PROGRAM=kitty');

        TerminalNotification::notify();

        $output = implode('', $this->captured);
        $this->assertStringContainsString("\x07", $output);
        // OSC 99 should have title, body, and done markers
        $this->assertStringContainsString(']99;i=', $output);
        $this->assertStringContainsString('p=title;KosmoKrator', $output);
        $this->assertStringContainsString('p=body;Response ready', $output);
        $this->assertStringContainsString('d=1:a=focus;', $output);
    }

    // ---------------------------------------------------------------
    // Unknown terminal: BEL only, no OSC
    // ---------------------------------------------------------------

    public function test_unknown_terminal_bell_only(): void
    {
        putenv('TERM_PROGRAM=xterm-256color');

        TerminalNotification::notify();

        // Only one write: the BEL
        $this->assertCount(1, $this->captured);
        $this->assertSame("\x07", $this->captured[0]);
    }

    // ---------------------------------------------------------------
    // AnsiRenderer integration
    // ---------------------------------------------------------------

    public function test_ansi_renderer_notifies_on_idle_after_thinking(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');
        $renderer = new AnsiRenderer;

        $notifications = 0;
        TerminalNotification::setWriter(function (string $data) use (&$notifications): void {
            if (str_contains($data, "\x07")) {
                $notifications++;
            }
        });

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->setPhase(AgentPhase::Idle);

        $this->assertSame(1, $notifications);
    }

    public function test_ansi_renderer_skips_double_idle(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');
        $renderer = new AnsiRenderer;

        $notifications = 0;
        TerminalNotification::setWriter(function (string $data) use (&$notifications): void {
            if (str_contains($data, "\x07")) {
                $notifications++;
            }
        });

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->setPhase(AgentPhase::Idle);
        $renderer->setPhase(AgentPhase::Idle); // second Idle — should not notify

        $this->assertSame(1, $notifications);
    }

    public function test_ansi_renderer_notifies_after_tools_phase(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');
        $renderer = new AnsiRenderer;

        $notifications = 0;
        TerminalNotification::setWriter(function (string $data) use (&$notifications): void {
            if (str_contains($data, "\x07")) {
                $notifications++;
            }
        });

        $renderer->setPhase(AgentPhase::Tools);
        $renderer->setPhase(AgentPhase::Idle);

        $this->assertSame(1, $notifications);
    }

    public function test_ansi_renderer_idle_without_active_phase_does_not_notify(): void
    {
        putenv('TERM_PROGRAM=unknown-terminal');
        $renderer = new AnsiRenderer;

        $notifications = 0;
        TerminalNotification::setWriter(function (string $data) use (&$notifications): void {
            if (str_contains($data, "\x07")) {
                $notifications++;
            }
        });

        // Idle without prior Thinking/Tools — should not notify
        $renderer->setPhase(AgentPhase::Idle);

        $this->assertSame(0, $notifications);
    }
}
