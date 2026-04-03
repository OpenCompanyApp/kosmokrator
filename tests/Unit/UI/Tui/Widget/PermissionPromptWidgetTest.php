<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\PermissionPromptWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * @psalm-suppress PossiblyNullFunctionCall
 */
final class PermissionPromptWidgetTest extends TestCase
{
    private function makePreview(
        string $title = 'Invocation Request',
        string $toolLabel = 'Bash',
        string $summary = 'Execute command',
        array $sections = [],
    ): array {
        return [
            'title' => $title,
            'tool_label' => $toolLabel,
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    public function test_constructor_sets_tool_name_and_preview(): void
    {
        $preview = $this->makePreview();
        $widget = new PermissionPromptWidget('bash', $preview);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
    }

    public function test_render_shows_title(): void
    {
        $preview = $this->makePreview(title: 'Test Title');
        $widget = new PermissionPromptWidget('bash', $preview);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Test Title', $content);
    }

    public function test_render_shows_tool_label(): void
    {
        $preview = $this->makePreview(toolLabel: 'File Write');
        $widget = new PermissionPromptWidget('file_write', $preview);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('File Write', $content);
    }

    public function test_render_shows_approval_options(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Allow once', $content);
        $this->assertStringContainsString('Always allow', $content);
        $this->assertStringContainsString('Guardian', $content);
        $this->assertStringContainsString('Prometheus', $content);
        $this->assertStringContainsString('Deny', $content);
    }

    public function test_render_shows_sections(): void
    {
        $preview = $this->makePreview(sections: [
            ['label' => 'Command', 'lines' => ['echo hello']],
            ['label' => 'Scope', 'lines' => ['shell access']],
        ]);
        $widget = new PermissionPromptWidget('bash', $preview);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Command', $content);
        $this->assertStringContainsString('echo hello', $content);
        $this->assertStringContainsString('Scope', $content);
        $this->assertStringContainsString('shell access', $content);
    }

    public function test_render_produces_bordered_box(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringContainsString('┌', $lines[0]);
        $this->assertStringContainsString('┐', $lines[0]);

        $lastLine = $lines[count($lines) - 1];
        $this->assertStringContainsString('└', $lastLine);
        $this->assertStringContainsString('┘', $lastLine);
    }

    public function test_render_shows_key_hints(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Enter confirm', $content);
        $this->assertStringContainsString('Esc deny', $content);
    }

    public function test_onConfirm_callback_receives_selected_option(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $received = null;

        $widget->onConfirm(function (string $value) use (&$received): void {
            $received = $value;
        });

        // Default selected index is 0 ("allow")
        $widget->handleInput("\r"); // Enter

        $this->assertSame('allow', $received);
    }

    public function test_onConfirm_callback_receives_deny_option(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $received = null;

        $widget->onConfirm(function (string $value) use (&$received): void {
            $received = $value;
        });

        // Navigate down to "deny" (index 4)
        $widget->handleInput("\x1b[B"); // down → 1
        $widget->handleInput("\x1b[B"); // down → 2
        $widget->handleInput("\x1b[B"); // down → 3
        $widget->handleInput("\x1b[B"); // down → 4
        $widget->handleInput("\r"); // enter

        $this->assertSame('deny', $received);
    }

    public function test_onDismiss_callback_invoked_on_escape(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput("\x1b"); // Escape

        $this->assertTrue($called);
    }

    public function test_handleInput_down_cycles_options(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $received = null;

        $widget->onConfirm(function (string $value) use (&$received): void {
            $received = $value;
        });

        $widget->handleInput("\x1b[B"); // down → index 1 = "always"
        $widget->handleInput("\r"); // enter

        $this->assertSame('always', $received);
    }

    public function test_handleInput_up_wraps_around(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $received = null;

        $widget->onConfirm(function (string $value) use (&$received): void {
            $received = $value;
        });

        // Up from index 0 wraps to last index (4 = "deny")
        $widget->handleInput("\x1b[A"); // up → wraps to 4
        $widget->handleInput("\r"); // enter

        $this->assertSame('deny', $received);
    }

    public function test_handleInput_down_wraps_around(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $received = null;

        $widget->onConfirm(function (string $value) use (&$received): void {
            $received = $value;
        });

        // Down 5 times: 0→1→2→3→4→0 (wraps)
        $widget->handleInput("\x1b[B"); // 1
        $widget->handleInput("\x1b[B"); // 2
        $widget->handleInput("\x1b[B"); // 3
        $widget->handleInput("\x1b[B"); // 4
        $widget->handleInput("\x1b[B"); // 0 (wraps)
        $widget->handleInput("\r"); // enter

        $this->assertSame('allow', $received);
    }

    public function test_onConfirm_returns_static_for_chaining(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $result = $widget->onConfirm(static fn (string $v): bool => true);

        $this->assertSame($widget, $result);
    }

    public function test_onDismiss_returns_static_for_chaining(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $result = $widget->onDismiss(static fn (): bool => true);

        $this->assertSame($widget, $result);
    }

    public function test_render_handles_empty_sections(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview(sections: []));

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
    }

    public function test_render_wraps_long_text(): void
    {
        $longSummary = str_repeat('word ', 40);
        $preview = $this->makePreview(summary: $longSummary);
        $widget = new PermissionPromptWidget('bash', $preview);

        $lines = $widget->render(new RenderContext(60, 24));

        $this->assertNotEmpty($lines);
    }
}
