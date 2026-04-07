# Widget Unit Testing Framework

> A structured unit testing framework that gives every widget (existing and planned) deterministic render assertions, input simulation, resize testing, and signal reactivity checks — all running headlessly via `VirtualTerminal` + `ScreenBuffer`.

## Why This Plan Exists

The companion snapshot testing plan (`01-snapshot-testing.md`) handles **visual regression** by comparing full rendered output against golden files. This plan handles **behavioral correctness** — the unit test layer that validates:

- Widgets produce structurally correct output (borders, content, truncation)
- Widgets respond correctly to input (arrow keys, Enter, Esc)
- Widgets react to state changes (signal updates, data changes)
- Widgets handle edge cases (narrow terminals, empty content, CJK/emoji)
- Widgets satisfy the `render()` contract at every size

Snapshot tests catch *unintentional* visual changes. Unit tests catch *incorrect* behavior.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│  WidgetTestCase (abstract base class)                               │
│                                                                     │
│  ┌─────────────────┐  ┌──────────────────┐  ┌───────────────────┐  │
│  │ renderWidget()   │  │ simulateInput()  │  │ assertRenderX()   │  │
│  │                  │  │                  │  │                   │  │
│  │ widget → VTerm   │  │ send keys to     │  │ equals / contains │  │
│  │ → ScreenBuffer   │  │ FocusableWidget  │  │ / ansi / cell     │  │
│  │ → lines[]        │  │ via StdinBuffer  │  │ assertions        │  │
│  └─────────────────┘  └──────────────────┘  └───────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │ Test Matrix: @dataProvider sizes                             │   │
│  │   60×20  80×24  120×30  200×50                              │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │ Signal Testing: mock signals → verify widget re-renders     │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 1. WidgetTestCase — Base Test Class

### File: `tests/Unit/UI/Tui/WidgetTestCase.php`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\Tests\Unit\UI\Tui\Helper\WidgetRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Base test class for all widget unit tests.
 *
 * Provides rendering, input simulation, and assertion helpers.
 * Every widget test extends this class.
 */
abstract class WidgetTestCase extends TestCase
{
    // ─── Rendering ──────────────────────────────────────────────

    /**
     * Render a widget at the given dimensions and return the screen lines.
     *
     * Uses ScreenBuffer to normalize ANSI output into a stable cell grid.
     * Returns plain-text lines (ANSI codes stripped) for content assertions.
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns  Terminal width (default 80)
     * @param int $rows     Terminal height (default 24)
     * @return string[]  One string per screen row, trailing spaces trimmed
     */
    protected function renderWidget(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        return WidgetRenderer::renderPlain($widget, $columns, $rows);
    }

    /**
     * Render a widget and return ANSI-styled lines.
     *
     * Use when testing color/style correctness.
     *
     * @return string[]  Lines with ANSI escape codes preserved
     */
    protected function renderWidgetStyled(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        return WidgetRenderer::renderStyled($widget, $columns, $rows);
    }

    /**
     * Render a widget and return the raw cell array.
     *
     * Use for pixel-level assertions (e.g., "cell at row 3, col 5 is '✓'").
     *
     * @return array<int, array<int, array{char: string, style: string}>>
     */
    protected function renderWidgetCells(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        return WidgetRenderer::renderCells($widget, $columns, $rows);
    }

    /**
     * Render a widget through the full VirtualTerminal pipeline.
     *
     * This starts the terminal, writes render output through it,
     * and captures the result. Use for widgets that depend on
     * terminal behavior (cursor movement, resize callbacks, etc.)
     */
    protected function renderViaVirtualTerminal(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): VirtualTerminal {
        return WidgetRenderer::renderViaTerminal($widget, $columns, $rows);
    }

    // ─── Assertions ─────────────────────────────────────────────

    /**
     * Assert that the rendered output exactly matches the expected lines.
     *
     * Compares plain-text output line by line. Trailing whitespace is ignored.
     *
     * @param string[] $expectedLines  The expected output lines
     * @param AbstractWidget $widget  The widget to render and check
     * @param int $columns  Terminal width
     * @param int $rows  Terminal height
     */
    protected function assertRenderEquals(
        array $expectedLines,
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $actualLines = $this->renderWidget($widget, $columns, $rows);
        $actualTrimmed = array_map(rtrim(...), $actualLines);
        $expectedTrimmed = array_map(rtrim(...), $expectedLines);

        $this->assertSame(
            $expectedTrimmed,
            $actualTrimmed,
            $this->formatRenderDiff($expectedTrimmed, $actualTrimmed),
        );
    }

    /**
     * Assert that the rendered output contains the given substring.
     *
     * Searches plain-text output for the needle. Useful for checking
     * that specific labels, icons, or status text appear.
     *
     * @param string $needle  The substring to search for
     * @param AbstractWidget $widget  The widget to render and check
     * @param int $columns  Terminal width
     * @param int $rows  Terminal height
     */
    protected function assertRenderContains(
        string $needle,
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $actualLines = $this->renderWidget($widget, $columns, $rows);
        $screen = implode("\n", $actualLines);

        $this->assertStringContainsString(
            $needle,
            $screen,
            sprintf(
                "Failed asserting that rendered output contains '%s'.\nActual output:\n%s",
                $needle,
                $screen,
            ),
        );
    }

    /**
     * Assert that the rendered output does NOT contain the given substring.
     */
    protected function assertRenderNotContains(
        string $needle,
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $actualLines = $this->renderWidget($widget, $columns, $rows);
        $screen = implode("\n", $actualLines);

        $this->assertStringNotContainsString($needle, $screen);
    }

    /**
     * Assert that the styled output contains a specific ANSI escape sequence.
     *
     * Use this to verify that widgets emit correct color codes, bold, dim, etc.
     * Example: assertContainsAnsi($widget, "\x1b[1;37m"); // bold white
     *
     * @param string $sequence  The ANSI escape sequence to search for
     * @param AbstractWidget $widget  The widget to render and check
     * @param int $columns  Terminal width
     * @param int $rows  Terminal height
     */
    protected function assertContainsAnsi(
        AbstractWidget $widget,
        string $sequence,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $styledLines = $this->renderWidgetStyled($widget, $columns, $rows);
        $styledScreen = implode("\n", $styledLines);

        $this->assertStringContainsString(
            $sequence,
            $styledScreen,
            sprintf(
                "Failed asserting that styled output contains ANSI sequence %s.\nRaw styled output (hex):\n%s",
                bin2hex($sequence),
                bin2hex($styledScreen),
            ),
        );
    }

    /**
     * Assert that a specific cell has the expected character.
     *
     * @param int $row  Row index (0-based)
     * @param int $col  Column index (0-based)
     * @param string $expectedChar  Expected character at that cell
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns  Terminal width
     * @param int $rows  Terminal height
     */
    protected function assertCellEquals(
        int $row,
        int $col,
        string $expectedChar,
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $cells = $this->renderWidgetCells($widget, $columns, $rows);
        $this->assertArrayHasKey($row, $cells, "Row {$row} does not exist in rendered output");
        $this->assertArrayHasKey($col, $cells[$row], "Column {$col} does not exist in row {$row}");

        $actual = $cells[$row][$col]['char'];
        $this->assertSame(
            $expectedChar,
            $actual,
            sprintf("Cell at (%d, %d) expected '%s', got '%s'", $row, $col, $expectedChar, $actual),
        );
    }

    /**
     * Assert that no rendered line exceeds the given width.
     *
     * Validates the render contract: every line must fit within $columns.
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns  Terminal width
     * @param int $rows  Terminal height
     */
    protected function assertNoLineExceedsWidth(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        foreach ($lines as $i => $line) {
            $visibleWidth = \Symfony\Component\Tui\Ansi\AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(
                $columns,
                $visibleWidth,
                sprintf("Line %d exceeds terminal width (visible %d > %d)", $i, $visibleWidth, $columns),
            );
        }
    }

    /**
     * Assert that the widget renders without throwing an exception.
     *
     * Use for smoke tests on complex widgets.
     */
    protected function assertRendersCleanly(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $lines = $widget->render(new RenderContext($columns, $rows));
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines, 'Widget must produce at least one line');
    }

    // ─── Input Simulation ───────────────────────────────────────

    /**
     * Simulate keyboard input on a focusable widget.
     *
     * Sends raw input bytes through the VirtualTerminal's StdinBuffer
     * for proper sequence parsing (arrow keys, Ctrl+key, etc.).
     *
     * Returns the terminal for further assertions.
     *
     * @param FocusableInterface $widget  The widget to send input to
     * @param string $input  Raw input bytes (use Key constants or escape sequences)
     * @return VirtualTerminal  The terminal with captured output
     */
    protected function simulateInput(
        FocusableInterface $widget,
        string $input,
    ): VirtualTerminal {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->start(
            onInput: fn(string $data) => $widget->handleInput($data),
            onResize: fn() => null,
            onKittyProtocolActivated: fn() => null,
        );

        $terminal->simulateInput($input);
        $terminal->stop();

        return $terminal;
    }

    /**
     * Send a sequence of key inputs to a focusable widget.
     *
     * @param FocusableInterface $widget  The widget to interact with
     * @param string[] $inputs  Array of raw input bytes
     */
    protected function sendKeys(
        FocusableInterface $widget,
        array $inputs,
    ): void {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->start(
            onInput: fn(string $data) => $widget->handleInput($data),
            onResize: fn() => null,
            onKittyProtocolActivated: fn() => null,
        );

        foreach ($inputs as $input) {
            $terminal->simulateInput($input);
        }

        $terminal->stop();
    }

    // ─── Resize Simulation ──────────────────────────────────────

    /**
     * Simulate a terminal resize and re-render the widget.
     *
     * @param AbstractWidget $widget  The widget to test
     * @param int $fromCols  Original width
     * @param int $fromRows  Original height
     * @param int $toCols  New width
     * @param int $toRows  New height
     * @return string[]  Rendered lines at the new size
     */
    protected function renderAfterResize(
        AbstractWidget $widget,
        int $fromCols,
        int $fromRows,
        int $toCols,
        int $toRows,
    ): array {
        // First render at original size (establishes state)
        $this->renderWidget($widget, $fromCols, $fromRows);

        // Simulate resize
        $terminal = new VirtualTerminal($fromCols, $fromRows);
        $terminal->start(
            onInput: fn() => null,
            onResize: function () use ($widget, $toCols, $toRows): void {
                // Widget tree would handle resize here
            },
            onKittyProtocolActivated: fn() => null,
        );
        $terminal->simulateResize($toCols, $toRows);
        $terminal->stop();

        // Render at new size
        return $this->renderWidget($widget, $toCols, $toRows);
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Standard test dimension matrix.
     *
     * @return array<string, array{int, int}>
     */
    public static function sizeProvider(): array
    {
        return [
            'narrow (60×20)'   => [60, 20],
            'default (80×24)'  => [80, 24],
            'wide (120×30)'    => [120, 30],
            'ultrawide (200×50)' => [200, 50],
        ];
    }

    /**
     * Minimum viable size for most widgets.
     */
    public static function minSizeProvider(): array
    {
        return [
            'minimal' => [40, 12],
            'narrow'  => [50, 16],
        ];
    }

    /**
     * Format a human-readable diff between expected and actual render output.
     */
    private function formatRenderDiff(array $expected, array $actual): string
    {
        $diff = "\nRender output mismatch:\n";
        $diff .= str_repeat('─', 60) . "\n";

        $maxLines = max(count($expected), count($actual));
        for ($i = 0; $i < $maxLines; $i++) {
            $exp = $expected[$i] ?? '';
            $act = $actual[$i] ?? '';

            if ($exp === $act) {
                $diff .= sprintf("%4d │ %s\n", $i + 1, $exp);
            } else {
                if ($exp !== '') {
                    $diff .= sprintf("%4d │ - %s\n", $i + 1, $exp);
                }
                if ($act !== '') {
                    $diff .= sprintf("%4d │ + %s\n", $i + 1, $act);
                }
            }
        }

        return $diff;
    }
}
```

---

## 2. WidgetRenderer — Rendering Helper

### File: `tests/Unit/UI/Tui/Helper/WidgetRenderer.php`

This class isolates the render-to-buffer pipeline. It's shared between `WidgetTestCase` (unit tests) and `SnapshotTestCase` (snapshot tests).

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Helper;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Render widgets through VirtualTerminal + ScreenBuffer for testing.
 *
 * Provides three output modes:
 * - Plain:   ANSI-stripped text lines for content assertions
 * - Styled:  ANSI-preserved lines for style assertions
 * - Cells:   Raw cell array for pixel-level assertions
 */
final class WidgetRenderer
{
    /**
     * Render a widget to plain-text lines (ANSI codes stripped).
     *
     * @return string[]
     */
    public static function renderPlain(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        // Split the screen back into lines
        return explode("\n", $buffer->getScreen());
    }

    /**
     * Render a widget to styled lines (ANSI codes preserved).
     *
     * @return string[]
     */
    public static function renderStyled(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        return explode("\n", $buffer->getStyledScreen());
    }

    /**
     * Render a widget to the raw cell array.
     *
     * @return array<int, array<int, array{char: string, style: string}>>
     */
    public static function renderCells(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        return $buffer->getCells();
    }

    /**
     * Render a widget through a full VirtualTerminal instance.
     *
     * Use for integration tests that need terminal behavior (resize, input routing, etc.)
     */
    public static function renderViaTerminal(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): VirtualTerminal {
        $terminal = new VirtualTerminal($columns, $rows);
        $terminal->start(
            onInput: fn() => null,
            onResize: fn() => null,
            onKittyProtocolActivated: fn() => null,
        );

        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        foreach ($lines as $line) {
            $terminal->write($line . "\r\n");
        }

        $terminal->stop();

        return $terminal;
    }
}
```

---

## 3. Assertion Deep Dive

### `assertRenderEquals` — Exact Output Assertion

Compares the full rendered output against expected lines. Use for widgets with deterministic, fully-known output:

```php
public function test_border_footer_renders_correctly(): void
{
    $widget = new BorderFooterWidget('v1.0.0', 'agent-mode');

    $this->assertRenderEquals(
        [
            '─ v1.0.0 ─ agent-mode ────────────────────────────────────────────────',
        ],
        $widget,
        columns: 72,
    );
}
```

### `assertRenderContains` — Substring Assertion

Check that specific text appears in the rendered output without matching the entire screen:

```php
public function test_discovery_batch_shows_tool_counts(): void
{
    $widget = new DiscoveryBatchWidget([
        ['name' => 'file_read', 'label' => 'src/Foo.php', 'detail' => 'content', 'summary' => '', 'status' => 'success'],
        ['name' => 'glob', 'label' => '**/*.php', 'detail' => '12 files', 'summary' => '', 'status' => 'success'],
        ['name' => 'grep', 'label' => 'pattern', 'detail' => '3 matches', 'summary' => '', 'status' => 'success'],
    ]);

    $this->assertRenderContains('1 read', $widget);
    $this->assertRenderContains('1 glob', $widget);
    $this->assertRenderContains('1 search', $widget);
    $this->assertRenderContains('Reading the omens', $widget);
}
```

### `assertContainsAnsi` — ANSI Sequence Assertion

Verify that specific styling appears in the output. Useful for testing:
- Selected option highlighting (bold/fg color)
- Error messages (red foreground)
- Dim text for metadata
- Border colors

```php
public function test_permission_prompt_highlights_selected(): void
{
    $widget = new PermissionPromptWidget('bash', [
        'title' => 'Invocation Request',
        'tool_label' => 'Bash',
        'summary' => 'Execute command',
        'sections' => [
            ['label' => 'Command', 'lines' => ['echo hello']],
        ],
    ]);

    // The "Allow once" option should have the selection cursor (›)
    $this->assertRenderContains('›', $widget);
    $this->assertRenderContains('Allow once', $widget);

    // The selected option label should be styled with white (bold)
    $this->assertContainsAnsi($widget, "\x1b[1;37m"); // bold white
}
```

### `assertCellEquals` — Pixel-Level Assertion

Check a specific cell's character. Use for border integrity, icon placement:

```php
public function test_box_drawing_top_left_corner(): void
{
    $widget = new PermissionPromptWidget('bash', $this->makePreview());

    // Top-left corner should be '┌'
    $this->assertCellEquals(0, 0, '┌', $widget);
}
```

### `assertNoLineExceedsWidth` — Contract Validation

Every widget must satisfy the `render()` contract: no line exceeds `getColumns()`. This is the single most important assertion for every widget at every size:

```php
/**
 * @dataProvider sizeProvider
 */
public function test_no_line_exceeds_width(int $columns, int $rows): void
{
    $widget = new BashCommandWidget(str_repeat('arg ', 30));
    $widget->setResult("output\nmore output", true);

    $this->assertNoLineExceedsWidth($widget, $columns, $rows);
}
```

---

## 4. Input Simulation

### How It Works

`VirtualTerminal` has a `simulateInput()` method that processes raw bytes through `StdinBuffer`, matching the real terminal's input pipeline:

```
VirtualTerminal.simulateInput("\x1b[B")  // raw bytes
  → StdinBuffer.process("\x1b[B")
    → StdinBuffer parses CSI sequence
      → onInput callback fires with parsed key
        → widget.handleInput(data) called
```

### Key Constants for Input

```php
use Symfony\Component\Tui\Input\Key;

Key::UP          // "\x1b[A"
Key::DOWN        // "\x1b[B"
Key::RIGHT       // "\x1b[C"
Key::LEFT        // "\x1b[D"
Key::ENTER       // "\r"
Key::ESCAPE      // "\x1b"
Key::TAB         // "\t"
Key::BACKSPACE   // "\x7f"
Key::HOME        // "\x1b[H"
Key::END         // "\x1b[F"
Key::PAGE_UP     // "\x1b[5~"
Key::PAGE_DOWN   // "\x1b[6~"
```

Ctrl+key combos: `"\x01"` for Ctrl+A through `"\x1a"` for Ctrl+Z, `"\x0f"` for Ctrl+O.

### Example: Permission Prompt Navigation

```php
public function test_arrow_down_moves_selection(): void
{
    $widget = new PermissionPromptWidget('bash', $this->makePreview());

    // Default: "Allow once" selected
    $this->assertRenderContains('› Allow once', $widget);

    // Press down → "Always allow" selected
    $this->sendKeys($widget, [Key::DOWN]);
    $this->assertRenderContains('› Always allow', $widget);
    $this->assertRenderNotContains('› Allow once', $widget); // previous unselected
}

public function test_enter_confirms_selection(): void
{
    $confirmed = null;
    $widget = new PermissionPromptWidget('bash', $this->makePreview());
    $widget->onConfirm(function (string $value) use (&$confirmed): void {
        $confirmed = $value;
    });

    $this->sendKeys($widget, [Key::DOWN, Key::DOWN, Key::ENTER]);

    $this->assertSame('guardian', $confirmed);
}

public function test_escape_dismisses(): void
{
    $dismissed = false;
    $widget = new PermissionPromptWidget('bash', $this->makePreview());
    $widget->onDismiss(function () use (&$dismissed): void {
        $dismissed = true;
    });

    $this->sendKeys($widget, [Key::ESCAPE]);

    $this->assertTrue($dismissed);
}
```

### Example: Settings Workspace Keyboard Navigation

```php
public function test_tab_switches_category(): void
{
    $widget = $this->createSettingsWidget();

    // Default: first category selected
    $this->assertRenderContains('▸ Provider', $widget); // or similar indicator

    // Tab to next category
    $this->sendKeys($widget, [Key::TAB]);
    $this->assertRenderContains('▸ Model', $widget);
}
```

---

## 5. Test Matrix — Size Responsiveness

Every widget must be tested at the standard dimension matrix:

| Size | Columns | Rows | Use Case |
|------|---------|------|----------|
| Narrow | 60 | 20 | Small terminal, split pane |
| Default | 80 | 24 | Standard terminal |
| Wide | 120 | 30 | Large terminal, wide monitor |
| Ultrawide | 200 | 50 | Ultra-wide monitor, fullscreen |

### Pattern: `@dataProvider sizeProvider`

```php
/**
 * @dataProvider sizeProvider
 */
public function test_renders_at_all_sizes(int $columns, int $rows): void
{
    $widget = new BashCommandWidget('ls -la /var/log');
    $widget->setResult("file1.log\nfile2.log", true);

    // Must not crash
    $this->assertRendersCleanly($widget, $columns, $rows);

    // Must respect width contract
    $this->assertNoLineExceedsWidth($widget, $columns, $rows);

    // Must show essential content
    $this->assertRenderContains('ls -la', $widget, $columns, $rows);
}
```

### Minimum Size Tests

Some widgets have a minimum viable size. Below that, they should degrade gracefully (not crash):

```php
/**
 * @dataProvider minSizeProvider
 */
public function test_renders_at_minimum_size(int $columns, int $rows): void
{
    $widget = new QuestionWidget('Short question?');

    $this->assertRendersCleanly($widget, $columns, $rows);
    $this->assertNoLineExceedsWidth($widget, $columns, $rows);
}
```

### Truncation Tests

At narrow widths, content must truncate cleanly:

```php
public function test_long_command_truncates_at_40_cols(): void
{
    $command = str_repeat('very-long-argument ', 10);
    $widget = new BashCommandWidget($command);

    $lines = $this->renderWidget($widget, 40, 10);
    foreach ($lines as $i => $line) {
        $this->assertLessThanOrEqual(
            40,
            mb_strwidth($line),
            "Line {$i} exceeds 40 columns: {$line}",
        );
    }
}
```

---

## 6. Signal / State Reactivity Testing

Once the reactive signal system from `01-reactive-state/` is in place, widgets will re-render when signals change. Unit tests must verify this:

### Pattern: State Change → Re-render

```php
public function test_widget_updates_on_state_change(): void
{
    $widget = new BashCommandWidget('ls');
    
    // Before result: shows "running"
    $this->assertRenderContains('running', $widget);

    // After success: shows success indicator
    $widget->setResult("file1\nfile2", true);
    $this->assertRenderNotContains('running', $widget);
    $this->assertRenderContains('file1', $widget);
}

public function test_collapsible_toggle_changes_output(): void
{
    $widget = new CollapsibleWidget(
        header: '✓',
        content: implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 20))),
        lineCount: 20,
    );

    // Collapsed: shows preview + "+17 lines" hint
    $this->assertRenderContains('+17 lines', $widget);
    $this->assertRenderNotContains('Line 20', $widget);

    // Expanded: shows all content
    $widget->toggle();
    $this->assertRenderNotContains('+17 lines', $widget);
    $this->assertRenderContains('Line 20', $widget);
}
```

### Pattern: Signal-Based Widgets (Future)

When widgets bind to signals from the reactive state store:

```php
public function test_widget_reacts_to_signal_change(): void
{
    $store = new TuiStateStore();
    $widget = new HistoryStatusWidget($store->signal('history'));

    // Initial state: idle
    $store->set('history', ['status' => 'idle']);
    $this->assertRenderContains('idle', $widget);

    // Signal change: loading
    $store->set('history', ['status' => 'loading']);
    $widget->beforeRender(); // sync from signals
    $this->assertRenderContains('loading', $widget);
}
```

---

## 7. Widget Test Plans

### 7.1 Existing Widgets (13 total)

#### CollapsibleWidget

| Test | What it verifies |
|------|-----------------|
| `test_collapsed_shows_preview` | First 3 lines + "+N lines" hint |
| `test_expanded_shows_all` | Full content visible after toggle |
| `test_toggle_flips_state` | `isExpanded()` changes, render updates |
| `test_long_line_truncates` | Lines wider than columns are truncated |
| `test_preview_width_truncation` | Custom `previewWidth` parameter |
| `test_empty_content` | Handles empty string gracefully |
| `test_single_line_content` | No "+N lines" hint for 1-3 lines |
| `test_width_contract_*` (dataProvider) | No line exceeds columns at each size |

```php
final class CollapsibleWidgetTest extends WidgetTestCase
{
    public function test_collapsed_shows_preview_and_hint(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 10)));
        $widget = new CollapsibleWidget('✓', $content, 10);

        $lines = $this->renderWidget($widget, 80, 20);

        // First 3 lines visible
        $this->assertRenderContains('Line 1', $widget);
        $this->assertRenderContains('Line 2', $widget);
        $this->assertRenderContains('Line 3', $widget);
        // Hint about remaining
        $this->assertRenderContains('+7 lines', $widget);
        // Lines 4-10 NOT visible
        $this->assertRenderNotContains('Line 4', $widget);
    }

    public function test_expanded_shows_all_lines(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 10)));
        $widget = new CollapsibleWidget('✓', $content, 10);
        $widget->toggle();

        $this->assertTrue($widget->isExpanded());
        $this->assertRenderContains('Line 10', $widget);
    }

    /**
     * @dataProvider sizeProvider
     */
    public function test_no_line_exceeds_width(int $columns, int $rows): void
    {
        $content = str_repeat('x', 300);
        $widget = new CollapsibleWidget('✓', $content, 1);

        $this->assertNoLineExceedsWidth($widget, $columns, $rows);
    }
}
```

#### BashCommandWidget

| Test | What it verifies |
|------|-----------------|
| `test_running_collapsed` | Spinner + "running..." |
| `test_success_collapsed` | ✓ + preview + "+N lines" |
| `test_success_expanded` | Full output + collapse hint |
| `test_failure_auto_expands` | Failures expand automatically |
| `test_empty_output_success` | "(no output)" message |
| `test_empty_output_failure` | "command failed" message |
| `test_toggle_cycle` | Collapse → expand → collapse |
| `test_long_command_truncation` | Wide commands truncated |
| `test_cwd_prefix_stripped` | `cd /path && ` removed |
| `test_width_contract_*` | No overflow at all sizes |

#### PermissionPromptWidget (Focusable)

| Test | What it verifies |
|------|-----------------|
| `test_default_shows_allow_selected` | First option highlighted |
| `test_arrow_down_cycles` | Selection moves through options |
| `test_arrow_up_wraps` | Wraps from first to last |
| `test_enter_confirms` | `onConfirm` callback fires |
| `test_escape_dismisses` | `onDismiss` callback fires |
| `test_all_options_visible` | All 5 options rendered |
| `test_sections_rendered` | Tool call sections shown |
| `test_narrow_wrapping` | Content wraps at narrow width |
| `test_selected_has_cursor_marker` | "›" appears next to selected |
| `test_width_contract_*` | No overflow at all sizes |

#### PlanApprovalWidget (Focusable)

| Test | What it verifies |
|------|-----------------|
| `test_default_implement_selected` | Implement row highlighted |
| `test_arrow_navigation` | Cycles through rows |
| `test_permission_toggle` | Left/Right changes permission mode |
| `test_context_toggle` | Context strategy cycles |
| `test_confirm_callback` | onConfirm fires with correct params |
| `test_dismiss_callback` | onDismiss fires on Esc |
| `test_width_contract_*` | No overflow at all sizes |

#### DiscoveryBatchWidget (Toggleable)

| Test | What it verifies |
|------|-----------------|
| `test_collapsed_shows_summary` | Tool counts + item labels |
| `test_expanded_shows_details` | Full item details visible |
| `test_toggle_cycle` | Collapse ↔ expand |
| `test_empty_items` | "No omens yet" message |
| `test_mixed_statuses` | ✓/✗/● icons for success/error/pending |
| `test_tool_name_formatting` | Read/Scan/Search/Probe/Recall labels |
| `test_width_contract_*` | No overflow at all sizes |

#### HistoryStatusWidget

| Test | What it verifies |
|------|-----------------|
| `test_idle_state` | Idle indicator |
| `test_loading_state` | Loading spinner/message |
| `test_loaded_state` | Success indicator |
| `test_error_state` | Error indicator |
| `test_width_contract_*` | No overflow at all sizes |

#### QuestionWidget

| Test | What it verifies |
|------|-----------------|
| `test_basic_question` | Box with question text |
| `test_custom_title` | Title in border |
| `test_wrapping` | Long text wraps within borders |
| `test_no_bottom_border` | Bottom border suppressed |
| `test_empty_question` | Graceful handling |
| `test_width_contract_*` | No overflow at all sizes |

#### AnsweredQuestionsWidget

| Test | What it verifies |
|------|-----------------|
| `test_no_questions` | Empty state |
| `test_single_question` | One answered question |
| `test_multiple_questions` | Multiple numbered questions |
| `test_long_answer_truncation` | Truncation |
| `test_width_contract_*` | No overflow at all sizes |

#### BorderFooterWidget

| Test | What it verifies |
|------|-----------------|
| `test_basic_footer` | Horizontal rule + labels |
| `test_custom_labels` | Version and mode labels |
| `test_width_contract_*` | Border fills width exactly |

#### AnsiArtWidget

| Test | What it verifies |
|------|-----------------|
| `test_renders_art` | ASCII art content visible |
| `test_truncation` | Wide art truncated at narrow width |
| `test_width_contract_*` | No overflow at all sizes |

#### SwarmDashboardWidget (Focusable)

| Test | What it verifies |
|------|-----------------|
| `test_progress_bar` | █/░ progress indicator |
| `test_status_counts` | Done/running/queued/failed counts |
| `test_resource_display` | Tokens, cost, elapsed |
| `test_active_agents` | Agent list with progress bars |
| `test_failures_section` | Failure list |
| `test_dismiss_input` | Esc/q dismisses |
| `test_auto_refresh_label` | Footer shows refresh hint |
| `test_width_contract_*` | No overflow at all sizes |

#### SettingsWorkspaceWidget (Focusable)

| Test | What it verifies |
|------|-----------------|
| `test_category_navigation` | Arrow/tab switches categories |
| `test_field_navigation` | Arrow up/down in fields |
| `test_inline_editing` | Enter starts editing, text input works |
| `test_option_picker` | Picker overlay opens/closes |
| `test_scope_toggle` | Project/global scope switch |
| `test_escape_cancels_editing` | Esc exits edit mode |
| `test_width_contract_*` | No overflow at all sizes |

#### ToggleableWidgetInterface Compliance

Test that all `ToggleableWidgetInterface` implementations behave consistently:

```php
/**
 * @dataProvider toggleableWidgetProvider
 */
public function test_toggleable_contract(ToggleableWidgetInterface $widget): void
{
    // Initial state: collapsed
    $this->assertFalse($widget->isExpanded());

    // Toggle: expanded
    $widget->toggle();
    $this->assertTrue($widget->isExpanded());

    // Toggle again: collapsed
    $widget->toggle();
    $this->assertFalse($widget->isExpanded());

    // Explicit set
    $widget->setExpanded(true);
    $this->assertTrue($widget->isExpanded());
    $widget->setExpanded(false);
    $this->assertFalse($widget->isExpanded());
}
```

### 7.2 New Widgets (10 planned)

Each new widget from `02-widget-library/` follows the same test patterns. The test file is created alongside the widget.

#### ScrollbarWidget

| Test | What it verifies |
|------|-----------------|
| `test_thumb_position` | Thumb at correct position for scroll ratio |
| `test_no_scroll_needed` | Hidden when content fits |
| `test_full_thumb` | Full track when all content visible |
| `test_min_thumb_size` | Minimum 1-character thumb |
| `test_rail_rendering` | Track characters rendered |
| `test_width_contract_*` | Always fits in 1 column width |

#### TabsWidget

| Test | What it verifies |
|------|-----------------|
| `test_renders_tab_labels` | All tab names visible |
| `test_active_tab_highlight` | Active tab styled differently |
| `test_arrow_switches_tab` | Left/Right navigation |
| `test_truncation_many_tabs` | Overflow truncates with "…" |
| `test_empty_tabs` | No crash with zero tabs |
| `test_width_contract_*` | Truncation at narrow widths |

#### TreeWidget

| Test | What it verifies |
|------|-----------------|
| `test_renders_nodes` | Tree structure with indent guides |
| `test_expand_collapse` | Toggle node children |
| `test_nested_indentation` | Correct indent levels |
| `test_custom_icons` | Expand/collapse/leaf icons |
| `test_empty_tree` | Graceful empty state |
| `test_width_contract_*` | Deep nesting truncated |

#### SparklineWidget / GaugeWidget

| Test | What it verifies |
|------|-----------------|
| `test_bar_heights` | Bars at correct heights |
| `test_empty_data` | No crash on empty |
| `test_single_point` | One bar rendered |
| `test_overflow_clamps` | Values > max clamped |
| `test_gauge_fill_ratio` | Fill matches percentage |
| `test_width_contract_*` | Bars fit in width |

#### ImageWidget

| Test | What it verifies |
|------|-----------------|
| `test_kitty_protocol` | Kitty escape sequences emitted |
| `test_fallback_text` | Text label when protocol unavailable |
| `test_virtual_terminal_skip` | No image data in VirtualTerminal |
| `test_cleanup_sequence` | collectTerminalCleanupSequence() correct |
| `test_width_contract_*` | Dimensions respected |

#### ModalDialogSystem

| Test | What it verifies |
|------|-----------------|
| `test_overlay_renders` | Darkened background + centered box |
| `test_esc_dismisses` | Escape closes modal |
| `test_tab_traps_focus` | Tab cycles within modal |
| `test_nested_modals` | Stack behavior |
| `test_width_contract_*` | Modal fits in terminal |

#### ToastNotifications

| Test | What it verifies |
|------|-----------------|
| `test_info_toast` | Info toast renders |
| `test_error_toast` | Error toast with red styling |
| `test_dismiss_timer` | Auto-dismiss after timeout |
| `test_manual_dismiss` | Click/Esc dismisses |
| `test_stack_ordering` | Multiple toasts stack vertically |
| `test_width_contract_*` | Toasts fit in terminal |

#### StatusBarWidget

| Test | What it verifies |
|------|-----------------|
| `test_segments_render` | Left/center/right segments |
| `test_dynamic_content` | Updates on state change |
| `test_truncation` | Segments truncate when space limited |
| `test_separator` | Dividers between segments |
| `test_width_contract_*` | Bar fills exact width |

#### CommandPalette

| Test | What it verifies |
|------|-----------------|
| `test_search_input` | Typing filters commands |
| `test_arrow_navigation` | Up/Down in filtered list |
| `test_enter_selects` | Enter confirms selection |
| `test_esc_dismisses` | Escape closes palette |
| `test_no_results` | "No matching commands" message |
| `test_width_contract_*` | Palette fits in terminal |

---

## 8. Focusable Interface Compliance Test

A shared abstract test that all `FocusableInterface` widgets must pass:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Abstract test suite for FocusableInterface compliance.
 * Every focusable widget test class must implement getFocusableWidget()
 * and extend this class (or use these tests via traits).
 */
abstract class FocusableWidgetTestCase extends WidgetTestCase
{
    /** Create a focusable widget in its default state for testing. */
    abstract protected function createFocusableWidget(): FocusableInterface;

    public function test_focus_state_changes(): void
    {
        $widget = $this->createFocusableWidget();

        $this->assertFalse($widget->isFocused());

        $widget->setFocused(true);
        $this->assertTrue($widget->isFocused());

        $widget->setFocused(false);
        $this->assertFalse($widget->isFocused());
    }

    public function test_set_focused_returns_self(): void
    {
        $widget = $this->createFocusableWidget();

        $result = $widget->setFocused(true);
        $this->assertSame($widget, $result);
    }

    public function test_handle_input_does_not_throw(): void
    {
        $widget = $this->createFocusableWidget();

        // Common inputs should not throw
        $widget->handleInput("\x1b[B");  // down
        $widget->handleInput("\x1b[A");  // up
        $widget->handleInput("\r");       // enter
        $widget->handleInput("\x1b");     // escape

        $this->assertTrue(true); // No exception = pass
    }
}
```

---

## 9. Directory Structure

```
tests/Unit/UI/Tui/
├── Helper/
│   └── WidgetRenderer.php              # Render-to-buffer pipeline
├── WidgetTestCase.php                  # Base class with all assertions
├── FocusableWidgetTestCase.php         # Focusable compliance tests
├── SnapshotTestCase.php                # (from 01-snapshot-testing.md)
├── Widget/
│   ├── __snapshots__/                  # (from 01-snapshot-testing.md)
│   ├── CollapsibleWidgetTest.php
│   ├── BashCommandWidgetTest.php
│   ├── PermissionPromptWidgetTest.php
│   ├── PlanApprovalWidgetTest.php
│   ├── DiscoveryBatchWidgetTest.php
│   ├── HistoryStatusWidgetTest.php
│   ├── QuestionWidgetTest.php
│   ├── AnsweredQuestionsWidgetTest.php
│   ├── BorderFooterWidgetTest.php
│   ├── AnsiArtWidgetTest.php
│   ├── SwarmDashboardWidgetTest.php
│   ├── SettingsWorkspaceWidgetTest.php
│   ├── ToggleableContractTest.php      # Shared ToggleableWidgetInterface tests
│   │
│   │   # New widgets (created as widgets are built):
│   ├── ScrollbarWidgetTest.php
│   ├── TabsWidgetTest.php
│   ├── TreeWidgetTest.php
│   ├── SparklineWidgetTest.php
│   ├── GaugeWidgetTest.php
│   ├── ImageWidgetTest.php
│   ├── ModalDialogTest.php
│   ├── ToastNotificationTest.php
│   ├── StatusBarWidgetTest.php
│   └── CommandPaletteTest.php
```

---

## 10. Example: Complete Test File

### `CollapsibleWidgetTest.php`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\Tests\Unit\UI\Tui\WidgetTestCase;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;

final class CollapsibleWidgetTest extends WidgetTestCase
{
    // ─── Basic Rendering ────────────────────────────────────────

    public function test_collapsed_shows_preview_lines(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 10)));
        $widget = new CollapsibleWidget('✓', $content, 10);

        $this->assertRenderContains('Line 1', $widget);
        $this->assertRenderContains('Line 2', $widget);
        $this->assertRenderContains('Line 3', $widget);
        $this->assertRenderNotContains('Line 4', $widget);
    }

    public function test_collapsed_shows_remaining_lines_hint(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 10)));
        $widget = new CollapsibleWidget('✓', $content, 10);

        $this->assertRenderContains('+7 lines', $widget);
        $this->assertRenderContains('ctrl+o to reveal', $widget);
    }

    public function test_no_hint_when_content_fits_preview(): void
    {
        $content = "Line 1\nLine 2";
        $widget = new CollapsibleWidget('✓', $content, 2);

        $this->assertRenderNotContains('+', $widget);
        $this->assertRenderNotContains('lines', $widget);
    }

    // ─── Toggle Behavior ────────────────────────────────────────

    public function test_toggle_expands(): void
    {
        $content = implode("\n", array_map(fn(int $i) => "Line {$i}", range(1, 10)));
        $widget = new CollapsibleWidget('✓', $content, 10);

        $this->assertFalse($widget->isExpanded());
        $widget->toggle();
        $this->assertTrue($widget->isExpanded());

        $this->assertRenderContains('Line 10', $widget);
        $this->assertRenderNotContains('+7 lines', $widget);
    }

    public function test_set_expanded_true(): void
    {
        $content = "Only line";
        $widget = new CollapsibleWidget('✓', $content, 1);

        $widget->setExpanded(true);
        $this->assertTrue($widget->isExpanded());
    }

    public function test_toggle_cycles(): void
    {
        $content = "Content";
        $widget = new CollapsibleWidget('✓', $content, 1);

        $this->assertFalse($widget->isExpanded());
        $widget->toggle();
        $this->assertTrue($widget->isExpanded());
        $widget->toggle();
        $this->assertFalse($widget->isExpanded());
    }

    // ─── Header Rendering ───────────────────────────────────────

    public function test_header_appears_on_first_line(): void
    {
        $widget = new CollapsibleWidget('✓ Success', 'content', 1);
        $lines = $this->renderWidget($widget, 80, 10);

        $this->assertStringContainsString('✓ Success', $lines[0]);
    }

    // ─── Truncation ─────────────────────────────────────────────

    public function test_long_content_truncated_at_width(): void
    {
        $content = str_repeat('x', 200);
        $widget = new CollapsibleWidget('✓', $content, 1);

        $lines = $this->renderWidget($widget, 60, 10);
        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(
                60,
                mb_strwidth($line),
                "Line {$i} exceeds 60 columns",
            );
        }
    }

    // ─── Size Matrix ────────────────────────────────────────────

    /**
     * @dataProvider sizeProvider
     */
    public function test_no_line_exceeds_width(int $columns, int $rows): void
    {
        $content = implode("\n", array_map(
            fn(int $i) => str_repeat('x', rand(20, 150)),
            range(1, 20),
        ));
        $widget = new CollapsibleWidget('✓', $content, 20);

        $this->assertNoLineExceedsWidth($widget, $columns, $rows);
    }

    /**
     * @dataProvider sizeProvider
     */
    public function test_expanded_no_line_exceeds_width(int $columns, int $rows): void
    {
        $content = implode("\n", array_map(
            fn(int $i) => str_repeat('y', rand(20, 150)),
            range(1, 20),
        ));
        $widget = new CollapsibleWidget('✓', $content, 20);
        $widget->setExpanded(true);

        $this->assertNoLineExceedsWidth($widget, $columns, $rows);
    }

    // ─── Edge Cases ─────────────────────────────────────────────

    public function test_empty_content(): void
    {
        $widget = new CollapsibleWidget('✓', '', 0);

        $this->assertRendersCleanly($widget);
        $this->assertNoLineExceedsWidth($widget);
    }

    public function test_tabs_normalized(): void
    {
        $content = "col1\tcol2\tcol3";
        $widget = new CollapsibleWidget('✓', $content, 1);

        $this->assertRenderNotContains("\t", $widget);
    }
}
```

---

## 11. Example: Focusable Widget Test

### `PermissionPromptWidgetTest.php`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\Tests\Unit\UI\Tui\WidgetTestCase;
use Kosmokrator\UI\Tui\Widget\PermissionPromptWidget;
use Symfony\Component\Tui\Input\Key;

final class PermissionPromptWidgetTest extends WidgetTestCase
{
    private function makePreview(): array
    {
        return [
            'title' => 'Invocation Request',
            'tool_label' => 'Bash',
            'summary' => 'Execute command',
            'sections' => [
                ['label' => 'Command', 'lines' => ['echo hello']],
                ['label' => 'Scope', 'lines' => ['shell access']],
            ],
        ];
    }

    // ─── Focus ──────────────────────────────────────────────────

    public function test_focus_state(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->assertFalse($widget->isFocused());
        $widget->setFocused(true);
        $this->assertTrue($widget->isFocused());
    }

    // ─── Rendering ──────────────────────────────────────────────

    public function test_renders_all_options(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->assertRenderContains('Allow once', $widget);
        $this->assertRenderContains('Always allow', $widget);
        $this->assertRenderContains('Guardian', $widget);
        $this->assertRenderContains('Prometheus', $widget);
        $this->assertRenderContains('Deny', $widget);
    }

    public function test_renders_sections(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->assertRenderContains('Command', $widget);
        $this->assertRenderContains('echo hello', $widget);
        $this->assertRenderContains('Scope', $widget);
    }

    public function test_default_selection_is_allow(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->assertRenderContains('›', $widget);

        $lines = $this->renderWidget($widget);
        $allowLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Allow once')) {
                $allowLine = $line;
                break;
            }
        }
        $this->assertNotNull($allowLine);
        $this->assertStringContainsString('›', $allowLine);
    }

    // ─── Input Navigation ───────────────────────────────────────

    public function test_down_arrow_moves_to_always(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->sendKeys($widget, [Key::DOWN]);

        $lines = $this->renderWidget($widget);
        $alwaysLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Always allow')) {
                $alwaysLine = $line;
                break;
            }
        }
        $this->assertStringContainsString('›', $alwaysLine);
    }

    public function test_down_arrow_wraps_to_first(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        // 5 options, press down 5 times → back to first
        $this->sendKeys($widget, [
            Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN,
        ]);

        $lines = $this->renderWidget($widget);
        $denyLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Allow once')) {
                $denyLine = $line;
                break;
            }
        }
        $this->assertStringContainsString('›', $denyLine);
    }

    public function test_up_arrow_wraps_to_last(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());

        $this->sendKeys($widget, [Key::UP]);

        $lines = $this->renderWidget($widget);
        $denyLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Deny')) {
                $denyLine = $line;
                break;
            }
        }
        $this->assertStringContainsString('›', $denyLine);
    }

    // ─── Callbacks ──────────────────────────────────────────────

    public function test_enter_confirms_allow(): void
    {
        $confirmed = null;
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $widget->onConfirm(function (string $value) use (&$confirmed): void {
            $confirmed = $value;
        });

        $this->sendKeys($widget, [Key::ENTER]);

        $this->assertSame('allow', $confirmed);
    }

    public function test_enter_confirms_deny(): void
    {
        $confirmed = null;
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $widget->onConfirm(function (string $value) use (&$confirmed): void {
            $confirmed = $value;
        });

        $this->sendKeys($widget, [Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::ENTER]);

        $this->assertSame('deny', $confirmed);
    }

    public function test_escape_dismisses(): void
    {
        $dismissed = false;
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $widget->onDismiss(function () use (&$dismissed): void {
            $dismissed = true;
        });

        $this->sendKeys($widget, [Key::ESCAPE]);

        $this->assertTrue($dismissed);
    }

    // ─── Size Matrix ────────────────────────────────────────────

    /**
     * @dataProvider sizeProvider
     */
    public function test_no_line_exceeds_width(int $columns, int $rows): void
    {
        $widget = new PermissionPromptWidget('bash', [
            'title' => 'Invocation Request',
            'tool_label' => 'Bash',
            'summary' => str_repeat('x', 200),
            'sections' => [
                ['label' => 'Command', 'lines' => [str_repeat('y', 200)]],
            ],
        ]);

        $this->assertNoLineExceedsWidth($widget, $columns, $rows);
    }
}
```

---

## 12. Implementation Phases

### Phase 1: Framework (Day 1)

1. Create `tests/Unit/UI/Tui/Helper/WidgetRenderer.php`
2. Create `tests/Unit/UI/Tui/WidgetTestCase.php`
3. Create `tests/Unit/UI/Tui/FocusableWidgetTestCase.php`
4. Run smoke test: create one widget test to validate the framework

**Deliverables:**
- `WidgetRenderer` with 4 static methods
- `WidgetTestCase` with 10+ assertion methods
- `FocusableWidgetTestCase` with compliance tests

### Phase 2: Existing Widget Tests (Days 2–4)

Convert each existing widget test to use `WidgetTestCase`:

| Day | Widgets | Tests |
|-----|---------|-------|
| Day 2 | `CollapsibleWidget`, `BashCommandWidget`, `QuestionWidget`, `BorderFooterWidget` | ~30 tests |
| Day 3 | `PermissionPromptWidget`, `PlanApprovalWidget`, `DiscoveryBatchWidget` | ~35 tests |
| Day 4 | `HistoryStatusWidget`, `AnsweredQuestionsWidget`, `AnsiArtWidget`, `SwarmDashboardWidget`, `SettingsWorkspaceWidget` | ~30 tests |
| Day 4 | `ToggleableContractTest` (shared) | ~5 tests |

### Phase 3: New Widget Tests (Ongoing)

Each new widget from `02-widget-library/` gets a test file created alongside it:

| Widget | Priority | Tests |
|--------|----------|-------|
| ScrollbarWidget | P1 | ~6 |
| TabsWidget | P1 | ~6 |
| TreeWidget | P1 | ~6 |
| SparklineWidget | P2 | ~6 |
| GaugeWidget | P2 | ~6 |
| ImageWidget | P2 | ~5 |
| ModalDialogSystem | P1 | ~5 |
| ToastNotifications | P2 | ~6 |
| StatusBarWidget | P2 | ~5 |
| CommandPalette | P2 | ~6 |

### Phase 4: CI Integration (Day 5)

1. All widget tests run in CI (no TTY needed)
2. Test count tracked as a metric
3. `assertNoLineExceedsWidth` failures are hard failures (not warnings)

---

## 13. Test Count Summary

| Category | Widgets | Avg Tests/Widget | Total |
|----------|---------|-----------------|-------|
| Existing widgets | 13 | 8–12 | ~110 |
| New widgets | 10 | 5–7 | ~57 |
| Contract tests (Toggleable, Focusable) | — | — | ~10 |
| **Total** | **23** | | **~177** |

---

## 14. Design Decisions

### Q: Why a base class instead of a trait?

**Base class** for `WidgetTestCase`. This is standard PHPUnit convention — `extends TestCase`. The assertion methods are `protected` on the base class, which is the natural PHPUnit pattern. Traits are used for cross-cutting concerns (like `SnapshotTestCase`), but the primary widget test API belongs on the base class.

### Q: Why render through ScreenBuffer instead of just calling `render()` directly?

Calling `$widget->render($context)` returns `string[]` with ANSI codes. Direct string comparison is fragile because:
1. ANSI codes can appear in different orderings that produce the same visual result
2. Widget output may use cursor repositioning for in-place updates
3. `ScreenBuffer` normalizes all of this into a stable cell grid

For `assertNoLineExceedsWidth`, we do call `render()` directly and use `AnsiUtils::visibleWidth()` — that's a contract-level check, not a visual check.

### Q: Why `assertRenderContains` when we have `assertRenderEquals`?

`assertRenderEquals` requires knowing the exact output. `assertRenderContains` is for behavioral assertions: "does the success icon appear?" without needing to specify the entire layout. This makes tests resilient to unrelated layout changes.

### Q: How does this relate to snapshot testing?

**Complementary layers:**
- **Unit tests** (this plan): Assert behavior — navigation works, state changes propagate, width contract holds
- **Snapshot tests** (`01-snapshot-testing.md`): Assert visual output — exact rendering matches golden files

Unit tests catch logic bugs. Snapshot tests catch visual regressions. Both run in CI.

### Q: How to test widgets that depend on `Theme::` globals?

`Theme` methods return ANSI escape code strings. Two strategies:

1. **Plain-text assertions** (default): `assertRenderContains` strips ANSI via `ScreenBuffer.getScreen()`, making tests resilient to theme color changes
2. **Styled assertions** (opt-in): `assertContainsAnsi` checks for specific sequences when color correctness matters

For tests that verify *structure* (borders, text content, layout), always use plain-text assertions. For tests that verify *appearance* (selected state color, error color), use styled assertions.

---

## 15. Relationship to Existing Tests

The existing `tests/Unit/UI/Tui/Widget/*Test.php` files already test basic functionality. This plan **enhances** them:

| Current State | After This Plan |
|--------------|-----------------|
| `assertStringContainsString` on raw render output | `assertRenderContains` via ScreenBuffer |
| No size variation testing | `@dataProvider sizeProvider` matrix |
| No input simulation | `sendKeys()` through VirtualTerminal |
| No width contract checking | `assertNoLineExceedsWidth` |
| Substring-only assertions | Full assertion toolkit |

**Migration path:** Each existing test file is updated in-place to extend `WidgetTestCase` and use the new assertion methods. The test names and structure are preserved; only the assertion mechanism changes.

---

## 16. Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `tests/Unit/UI/Tui/Helper/WidgetRenderer.php` | Render-to-buffer pipeline |
| `tests/Unit/UI/Tui/WidgetTestCase.php` | Base test class |
| `tests/Unit/UI/Tui/FocusableWidgetTestCase.php` | Focusable compliance |

### Modified Files

| File | Change |
|------|--------|
| `tests/Unit/UI/Tui/Widget/CollapsibleWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/BashCommandWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/PermissionPromptWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/PlanApprovalWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/DiscoveryBatchWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/HistoryStatusWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/QuestionWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/AnsweredQuestionsWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/BorderFooterWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/AnsiArtWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/SwarmDashboardWidgetTest.php` | Extend WidgetTestCase |
| `tests/Unit/UI/Tui/Widget/SettingsWorkspaceWidgetTest.php` | Extend WidgetTestCase |
