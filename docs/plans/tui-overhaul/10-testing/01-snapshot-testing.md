# TUI Visual Snapshot Testing

> Catch visual regressions in terminal UI widgets by comparing rendered output against golden snapshots — exactly like Jest snapshot tests, but for ANSI terminal screens.

## Why Snapshot Testing?

Current widget tests (see `tests/Unit/UI/Tui/Widget/`) assert on the **presence of substrings** — `"running"`, `"✗"`, `"┌"`, etc. This tells us a widget renders *something*, but not whether the layout is correct. A widget could produce a garbled border, misaligned text, or a truncated line and the test would still pass.

Snapshot tests capture the **full rendered output** as a golden file. Any change — intentional or accidental — triggers a diff. The developer either accepts the change (`--update-snapshots`) or fixes the regression.

Claude Code ships hundreds of `.snap` files covering every UI state (loading, error, streaming, confirmed, dismissed, etc.). We should do the same.

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│  Test Case                                                   │
│                                                              │
│  1. Create widget with specific props/state                  │
│  2. renderViaScreenBuffer() → plain text screen              │
│  3. assertMatchesSnapshot("widget-name/state-description")   │
│                                                              │
│  On mismatch:                                                │
│  └─ show unified diff (ANSI-colored in terminal)             │
│  └─ fail test with "Snapshot does not match"                 │
│                                                              │
│  On --update-snapshots:                                      │
│  └─ overwrite .snap file                                     │
│  └─ report "X snapshots updated"                             │
└──────────────────────────────────────────────────────────────┘
```

### Key Components

| Component | Responsibility |
|-----------|---------------|
| `SnapshotTestCase` trait | PHPUnit trait with `assertMatchesSnapshot()` and snapshot I/O |
| `ScreenBufferRenderer` | Renders widget output through `VirtualTerminal` → `ScreenBuffer` → plain text |
| `.snap` files | Golden files stored alongside tests under `__snapshots__/` |
| `--update-snapshots` | CLI flag (env var `UPDATE_SNAPSHOTS=1`) to regenerate golden files |

---

## 1. Rendering Pipeline for Tests

The key insight: Symfony TUI already gives us every building block.

### Current Widget Render Flow (Production)

```
Widget.render(RenderContext) → string[] (ANSI lines)
  → Renderer.renderWidget() (adds chrome/borders/padding)
    → ScreenWriter.writeLines() (differential write to real Terminal)
```

### Snapshot Render Flow (Tests)

```
Widget.render(RenderContext) → string[] (ANSI lines)
  → VirtualTerminal.write(lines joined with \r\n)
    → ScreenBuffer.process(terminal output)
      → ScreenBuffer.getScreen() → plain text (no ANSI)
      → ScreenBuffer.getStyledScreen() → text with ANSI styles preserved
```

We do **not** need the full `Renderer` / `ScreenWriter` / `Tui` object graph. Widgets produce `string[]` lines from `render(RenderContext)`. We feed those lines into a `ScreenBuffer` to normalize away cursor movement, overwrites, and differential rendering artifacts.

### The `renderWidgetToBuffer()` Helper

```php
<?php

namespace Kosmokrator\Tests\Unit\UI\Tui\Helper;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Render a widget and capture the result in a ScreenBuffer for snapshot comparison.
 */
final class SnapshotRenderer
{
    /**
     * Render a widget and return the plain-text screen content.
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns  Terminal width (default 80)
     * @param int $rows  Terminal height (default 24)
     * @return string  Plain-text screen content (no ANSI codes)
     */
    public static function renderPlain(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): string {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        return $buffer->getScreen();
    }

    /**
     * Render a widget and return ANSI-styled screen content.
     *
     * Use this when the snapshot should preserve colors/styles for visual review.
     *
     * @return string  Screen content with ANSI style codes preserved
     */
    public static function renderStyled(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): string {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        return $buffer->getStyledScreen();
    }

    /**
     * Render a widget and return cell-level data for pixel-perfect comparison.
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
}
```

> **Why `ScreenBuffer` and not just `implode("\n", $lines)`?**
>
> Widget render output already contains ANSI escape codes for colors, borders, etc. `ScreenBuffer` normalizes cursor movement (`\x1b[H`, `\x1b[2K`) and overwrites into a stable cell grid. For simple widgets that just emit sequential lines, the result is identical — but `ScreenBuffer` also handles widgets that use cursor repositioning (e.g., progress bars, inline spinners).

---

## 2. Snapshot Format

### Plain Text Snapshots (default)

Human-readable, diffable, and Git-friendly. Each `.snap` file contains the widget's plain-text output:

```
// tests/Unit/UI/Tui/Widget/__snapshots__/QuestionWidget/basic-question.snap
┌─ Question ────────────────────────────────────────────────────────────┐
│ What approach would you prefer for the authentication module?        │
│ Consider the trade-offs between JWT sessions and OAuth2.             │
└──────────────────────────────────────────────────────────────────────┘
```

### Styled Snapshots (optional)

For widgets where color/style is critical (e.g., `PermissionPromptWidget` selected state), store styled snapshots with ANSI codes preserved:

```
// tests/Unit/UI/Tui/Widget/__snapshots__/PermissionPromptWidget/selected-allow.styled.snap
\x1b[36m┌─ \x1b[33mInvocation Request\x1b[0m\x1b[36m ───────────────────────────────────────────┐\x1b[0m
\x1b[36m│\x1b[0m \x1b[1mExecute command\x1b[0m                                                     \x1b[36m│\x1b[0m
...
```

### Snapshot File Header

Every `.snap` file starts with a metadata comment for traceability:

```
// Widget: Kosmokrator\UI\Tui\Widget\QuestionWidget
// State: basic question with short text
// Dimensions: 80×24
// Format: plain
// Updated: 2026-04-07
```

---

## 3. The `SnapshotTestCase` Trait

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit trait for visual snapshot testing of TUI widgets.
 *
 * Usage:
 *   class MyWidgetTest extends TestCase
 *   {
 *       use SnapshotTestCase;
 *
 *       public function test_renders_basic_state(): void
 *       {
 *           $widget = new MyWidget('hello');
 *           $screen = SnapshotRenderer::renderPlain($widget);
 *           $this->assertMatchesSnapshot($screen, 'my-widget/basic-state');
 *       }
 *   }
 *
 * Run with UPDATE_SNAPSHOTS=1 to regenerate golden files:
 *   UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/Unit/UI/Tui/Widget/
 */
trait SnapshotTestCase
{
    /**
     * Assert that the given screen content matches the stored snapshot.
     *
     * @param string $actual  The rendered screen content
     * @param string $snapshotName  Dot-path identifier (e.g., "question-widget/basic")
     */
    private function assertMatchesSnapshot(string $actual, string $snapshotName): void
    {
        $snapshotPath = $this->resolveSnapshotPath($snapshotName);
        $updateSnapshots = (bool) ($_ENV['UPDATE_SNAPSHOTS'] ?? false);

        if (!file_exists($snapshotPath)) {
            // First time: create the snapshot
            $this->writeSnapshot($snapshotPath, $actual);
            $this->markTestSkipped("Snapshot created: {$snapshotName}");
            return;
        }

        $expected = file_get_contents($snapshotPath);

        if ($actual === $expected) {
            $this->assertTrue(true); // Snapshot matches
            return;
        }

        if ($updateSnapshots) {
            $this->writeSnapshot($snapshotPath, $actual);
            // Still pass but note the update
            echo "\n  ↻ Snapshot updated: {$snapshotName}\n";
            return;
        }

        // Show diff and fail
        $diff = $this->computeDiff($expected, $actual, $snapshotName);
        throw new AssertionFailedError($diff);
    }

    /**
     * Resolve the filesystem path for a snapshot.
     *
     * Snapshots are stored in __snapshots__/ directories relative to the test class.
     *
     * @param string $name  Dot-path like "question-widget/basic-question"
     * @return string  Absolute path to the .snap file
     */
    private function resolveSnapshotPath(string $name): string
    {
        // Use the calling test's directory
        $reflection = new \ReflectionClass(static::class);
        $testDir = dirname($reflection->getFileName());
        $snapshotDir = $testDir . '/__snapshots__';

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        // Convert dot-path to file path: "widget/state" → "widget__state.snap"
        $filename = str_replace('/', '__', $name) . '.snap';

        return $snapshotDir . '/' . $filename;
    }

    private function writeSnapshot(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Compute a human-readable diff between expected and actual screen content.
     */
    private function computeDiff(string $expected, string $actual, string $name): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);

        $diff = "\nSnapshot mismatch: {$name}\n";
        $diff .= str_repeat('─', 60) . "\n";

        $maxLines = max(count($expectedLines), count($actualLines));
        for ($i = 0; $i < $maxLines; $i++) {
            $exp = $expectedLines[$i] ?? '';
            $act = $actualLines[$i] ?? '';

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

        $diff .= str_repeat('─', 60) . "\n";
        $diff .= "To update: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit ...\n";

        return $diff;
    }
}
```

---

## 4. Full Integration: Rendering Through the Symfony TUI Renderer

For integration-level snapshots that test chrome (borders, padding, backgrounds), we need the full `Renderer` pipeline:

```php
<?php

use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Render a widget through the full Renderer pipeline (with chrome, borders, padding)
 * and return the normalized screen buffer.
 */
function renderFullPipeline(
    AbstractWidget $widget,
    StyleSheet $styleSheet,
    int $columns = 80,
    int $rows = 24,
): string {
    $container = new ContainerWidget();
    $container->addChild($widget);

    $renderer = new Renderer($styleSheet);
    $lines = $renderer->render($container, $columns, $rows);

    $buffer = new ScreenBuffer($columns, $rows);
    $buffer->write(implode("\r\n", $lines));

    return $buffer->getScreen();
}
```

This is used for **integration snapshots** (see §6) where we want to verify the complete visual output including borders, padding, margins, and backgrounds applied by the `ChromeApplier`.

---

## 5. Per-Widget Snapshot Tests

### Directory Structure

```
tests/Unit/UI/Tui/Widget/
├── __snapshots__/
│   ├── QuestionWidget__basic-question.snap
│   ├── QuestionWidget__long-question-wraps.snap
│   ├── QuestionWidget__custom-title.snap
│   ├── QuestionWidget__no-bottom-border.snap
│   ├── PermissionPromptWidget__default-state.snap
│   ├── PermissionPromptWidget__selected-allow.snap
│   ├── PermissionPromptWidget__selected-always.snap
│   ├── PermissionPromptWidget__selected-guardian.snap
│   ├── PermissionPromptWidget__selected-prometheus.snap
│   ├── PermissionPromptWidget__selected-deny.snap
│   ├── PermissionPromptWidget__with-sections.snap
│   ├── PermissionPromptWidget__narrow-terminal.snap
│   ├── BashCommandWidget__running-collapsed.snap
│   ├── BashCommandWidget__success-collapsed.snap
│   ├── BashCommandWidget__success-expanded.snap
│   ├── BashCommandWidget__failure-collapsed.snap
│   ├── BashCommandWidget__failure-expanded.snap
│   ├── BashCommandWidget__empty-output.snap
│   ├── BashCommandWidget__long-output-collapsed.snap
│   ├── BashCommandWidget__narrow-terminal.snap
│   ├── DiscoveryBatchWidget__in-progress.snap
│   ├── DiscoveryBatchWidget__complete-success.snap
│   ├── DiscoveryBatchWidget__partial-failure.snap
│   ├── HistoryStatusWidget__idle.snap
│   ├── HistoryStatusWidget__loading.snap
│   ├── HistoryStatusWidget__loaded.snap
│   ├── SwarmDashboardWidget__overview.snap
│   ├── SwarmDashboardWidget__active-subagents.snap
│   └── SettingsWorkspaceWidget__main-view.snap
├── QuestionWidgetTest.php
├── PermissionPromptWidgetTest.php
├── BashCommandWidgetTest.php
└── ...
```

### Example: `QuestionWidget` Snapshot Test

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\Tests\Unit\UI\Tui\Helper\SnapshotRenderer;
use Kosmokrator\Tests\Unit\UI\Tui\SnapshotTestCase;
use Kosmokrator\UI\Tui\Widget\QuestionWidget;
use PHPUnit\Framework\TestCase;

final class QuestionWidgetSnapshotTest extends TestCase
{
    use SnapshotTestCase;

    public function test_basic_question(): void
    {
        $widget = new QuestionWidget('What is your preferred framework?');

        $screen = SnapshotRenderer::renderPlain($widget, 72, 10);
        $this->assertMatchesSnapshot($screen, 'QuestionWidget/basic-question');
    }

    public function test_long_question_wraps(): void
    {
        $widget = new QuestionWidget(
            'What approach would you prefer for the authentication module? ' .
            'Consider the trade-offs between JWT sessions and OAuth2 for scalability.'
        );

        $screen = SnapshotRenderer::renderPlain($widget, 60, 10);
        $this->assertMatchesSnapshot($screen, 'QuestionWidget/long-question-wraps');
    }

    public function test_custom_title(): void
    {
        $widget = new QuestionWidget(
            'Do you want to proceed?',
            title: 'Confirmation'
        );

        $screen = SnapshotRenderer::renderPlain($widget, 72, 10);
        $this->assertMatchesSnapshot($screen, 'QuestionWidget/custom-title');
    }

    public function test_no_bottom_border(): void
    {
        $widget = new QuestionWidget(
            'Choose a model:',
            showBottom: false
        );

        $screen = SnapshotRenderer::renderPlain($widget, 72, 10);
        $this->assertMatchesSnapshot($screen, 'QuestionWidget/no-bottom-border');
    }

    public function test_narrow_terminal(): void
    {
        $widget = new QuestionWidget(
            'How should we handle rate limiting for the external API calls?'
        );

        $screen = SnapshotRenderer::renderPlain($widget, 40, 10);
        $this->assertMatchesSnapshot($screen, 'QuestionWidget/narrow-terminal');
    }
}
```

### Example Snapshot Output

`tests/Unit/UI/Tui/Widget/__snapshots__/QuestionWidget__basic-question.snap`:

```
┌─ Question ───────────────────────────────────────────────────────────┐
│ What is your preferred framework?                                    │
└──────────────────────────────────────────────────────────────────────┘
```

`tests/Unit/UI/Tui/Widget/__snapshots__/QuestionWidget__long-question-wraps.snap`:

```
┌─ Question ───────────────────────────────────────────────────┐
│ What approach would you prefer for the                        │
│ authentication module? Consider the trade-offs between JWT   │
│ sessions and OAuth2 for scalability.                          │
└──────────────────────────────────────────────────────────────┘
```

`tests/Unit/UI/Tui/Widget/__snapshots__/QuestionWidget__narrow-terminal.snap`:

```
┌─ Question ─────────────────────────────────┐
│ How should we handle rate limiting for the │
│ external API calls?                        │
└────────────────────────────────────────────┘
```

---

## 6. State-Based Snapshots

Widgets are stateful. The same widget renders differently depending on internal state. We snapshot each meaningful state:

### `BashCommandWidget` States

| State | Snapshot Name | Description |
|-------|--------------|-------------|
| Running, collapsed | `BashCommandWidget/running-collapsed` | Spinner + command preview |
| Running, expanded | `BashCommandWidget/running-expanded` | Spinner + full command |
| Success, collapsed | `BashCommandWidget/success-collapsed` | ✓ + output preview + `+N lines` |
| Success, expanded | `BashCommandWidget/success-expanded` | ✓ + full output + collapse hint |
| Failure, collapsed | `BashCommandWidget/failure-collapsed` | ✗ + output preview |
| Failure, expanded | `BashCommandWidget/failure-expanded` | ✗ + full output |
| Empty success | `BashCommandWidget/success-empty` | ✓ + "no output" |
| Empty failure | `BashCommandWidget/failure-empty` | ✗ + "command failed" |
| Narrow terminal | `BashCommandWidget/narrow-40cols` | 40-column terminal rendering |

```php
<?php

final class BashCommandWidgetSnapshotTest extends TestCase
{
    use SnapshotTestCase;

    public function test_running_collapsed(): void
    {
        $widget = new BashCommandWidget('ls -la /var/log');
        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'BashCommandWidget/running-collapsed');
    }

    public function test_success_collapsed(): void
    {
        $output = implode("\n", array_map(
            fn(int $i): string => "file-{$i}.log",
            range(1, 10)
        ));

        $widget = new BashCommandWidget('ls -la /var/log');
        $widget->setResult($output, true);

        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'BashCommandWidget/success-collapsed');
    }

    public function test_failure_expanded(): void
    {
        $widget = new BashCommandWidget('bad_command --flag');
        $widget->setResult("command not found: bad_command\nExit code: 127", false);
        $widget->setExpanded(true);

        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'BashCommandWidget/failure-expanded');
    }

    public function test_narrow_terminal(): void
    {
        $widget = new BashCommandWidget('echo "This is a very long command that should wrap in a narrow terminal"');
        $widget->setResult('output', true);

        $screen = SnapshotRenderer::renderPlain($widget, 40, 20);
        $this->assertMatchesSnapshot($screen, 'BashCommandWidget/narrow-40cols');
    }
}
```

### `PermissionPromptWidget` States

The permission prompt has an internal selection cursor. We snapshot each selected option:

```php
<?php

final class PermissionPromptWidgetSnapshotTest extends TestCase
{
    use SnapshotTestCase;

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

    public function test_default_selected_allow(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'PermissionPromptWidget/selected-allow');
    }

    public function test_selected_always(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $widget->handleInput("\x1b[B"); // down → index 1
        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'PermissionPromptWidget/selected-always');
    }

    public function test_selected_deny(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $widget->handleInput("\x1b[B"); // 1
        $widget->handleInput("\x1b[B"); // 2
        $widget->handleInput("\x1b[B"); // 3
        $widget->handleInput("\x1b[B"); // 4
        $screen = SnapshotRenderer::renderPlain($widget);
        $this->assertMatchesSnapshot($screen, 'PermissionPromptWidget/selected-deny');
    }

    public function test_narrow_terminal(): void
    {
        $widget = new PermissionPromptWidget('bash', $this->makePreview());
        $screen = SnapshotRenderer::renderPlain($widget, 50, 20);
        $this->assertMatchesSnapshot($screen, 'PermissionPromptWidget/narrow-terminal');
    }
}
```

---

## 7. Integration Snapshots (Full Layouts)

Integration snapshots test the complete `TuiRenderer` output — header bar, message area, input prompt, and footer combined. These are coarser-grained but catch layout regressions that per-widget tests miss.

### Setup

```php
<?php

use Kosmokrator\UI\Tui\KosmokratorStyleSheet;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Render a full layout through the Renderer → ScreenBuffer pipeline.
 */
function renderLayout(ContainerWidget $root, int $columns = 80, int $rows = 24): string
{
    $renderer = new Renderer(new KosmokratorStyleSheet());
    $lines = $renderer->render($root, $columns, $rows);

    $buffer = new ScreenBuffer($columns, $rows);
    $buffer->write(implode("\r\n", $lines));

    return $buffer->getScreen();
}
```

### Example Integration Snapshots

| Snapshot | Description |
|----------|-------------|
| `Layout/streaming-response` | Agent is streaming a response (spinner, partial text) |
| `Layout/permission-prompt` | Permission prompt overlay on conversation |
| `Layout/error-state` | Error message in message area |
| `Layout/narrow-60cols` | Full layout at 60 columns |
| `Layout/wide-180cols` | Full layout at 180 columns |

These live under `tests/Integration/UI/Tui/Layout/__snapshots__/`.

---

## 8. Responsive / Resize Snapshots

Widgets must handle terminal resize gracefully. Snapshot tests at multiple widths catch wrapping bugs:

```php
/**
 * @dataProvider widthProvider
 */
public function test_renders_at_width(int $width): void
{
    $widget = new BashCommandWidget(str_repeat('arg ', 20));
    $widget->setResult("output\nmore output", true);

    $screen = SnapshotRenderer::renderPlain($widget, $width, 24);
    $this->assertMatchesSnapshot($screen, "BashCommandWidget/width-{$width}");
}

public static function widthProvider(): array
{
    return [
        'narrow'  => [40],
        'medium'  => [72],
        'default' => [80],
        'wide'    => [120],
        'ultrawide' => [180],
    ];
}
```

---

## 9. CI Integration

### No Real Terminal Required

The entire snapshot system runs headlessly:

- `VirtualTerminal` — no real TTY needed
- `ScreenBuffer` — pure PHP ANSI parser
- No `stty`, no `tput`, no `/dev/tty`

Works identically on GitHub Actions, GitLab CI, local macOS, and Linux.

### GitHub Actions Configuration

```yaml
# .github/workflows/tui-snapshots.yml
name: TUI Snapshot Tests

on: [push, pull_request]

jobs:
  snapshot-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run snapshot tests
        run: vendor/bin/phpunit tests/Unit/UI/Tui/Widget/ --filter Snapshot

      - name: Check for outdated snapshots
        run: |
          if git diff --name-only --exit-code tests/Unit/UI/Tui/Widget/__snapshots__/; then
            echo "✓ All snapshots up to date"
          else
            echo "❌ Snapshots differ. Run locally: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit ..."
            exit 1
          fi
```

### Update Snapshots in CI (Manual Trigger)

```yaml
  update-snapshots:
    if: github.event_name == 'workflow_dispatch'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install
      - run: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/Unit/UI/Tui/Widget/ --filter Snapshot
      - name: Create PR with updated snapshots
        uses: peter-evans/create-pull-request@v6
        with:
          title: "🔄 Update TUI snapshots"
          commit-message: "test: update TUI widget snapshots"
```

---

## 10. Diff Display

When a snapshot fails, the test output shows a unified diff with line numbers:

```
Snapshot mismatch: BashCommandWidget/success-collapsed
────────────────────────────────────────────────────────────
   1 │ ┌─ echo hello ────────────────────────────────────────────────────┐
   2 │ │ ✓ success                                    +2 lines hidden  │
   3 │ - └──────────────────────────────────────────────────────────────┘
   3 │ + └───────────────────────────────────────────────────────────────┘
────────────────────────────────────────────────────────────
To update: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit ...
```

For styled snapshots, we also support ANSI-colored diff output:

```php
/**
 * Produce an ANSI-colored diff for terminal output.
 */
private function computeColoredDiff(string $expected, string $actual): string
{
    $tempDir = sys_get_temp_dir();
    $expFile = $tempDir . '/snap_expected';
    $actFile = $tempDir . '/snap_actual';

    file_put_contents($expFile, $expected);
    file_put_contents($actFile, $actual);

    // Use `diff` for clean unified output with 3 lines of context
    $output = shell_exec("diff -U3 --color=always " . escapeshellarg($expFile) . " " . escapeshellarg($actFile));

    @unlink($expFile);
    @unlink($actFile);

    return $output ?? "(no diff available)";
}
```

---

## 11. HTML Snapshot Reports (Optional Enhancement)

`ScreenBufferHtmlRenderer` converts `ScreenBuffer` cells to HTML with inline CSS. We can use this to generate visual HTML reports:

```php
use Symfony\Component\Tui\Ansi\ScreenBufferHtmlRenderer;

/**
 * Generate an HTML report showing all snapshots for visual review.
 */
function generateSnapshotReport(array $results): string
{
    $renderer = new ScreenBufferHtmlRenderer();
    $html = '<html><head><style>
        body { background: #1e1e1e; color: #d4d4d4; font-family: monospace; }
        .snapshot { margin: 2em 0; border: 1px solid #444; padding: 1em; }
        .snapshot pre { line-height: 1.2; }
    </style></head><body>';

    foreach ($results as $name => $screenBuffer) {
        $html .= "<div class='snapshot'><h3>{$name}</h3>";
        $html .= "<pre>" . $renderer->convert($screenBuffer) . "</pre></div>";
    }

    $html .= '</body></html>';
    return $html;
}
```

Run via: `vendor/bin/phpunit --report-snapshots=report.html`

---

## 12. Implementation Phases

### Phase 1: Foundation (Day 1)

1. Create `tests/Unit/UI/Tui/Helper/SnapshotRenderer.php` — the `renderPlain()` / `renderStyled()` / `renderCells()` helper
2. Create `tests/Unit/UI/Tui/SnapshotTestCase.php` — the trait with `assertMatchesSnapshot()`, diff display, `UPDATE_SNAPSHOTS` env var
3. Add `UPDATE_SNAPSHOTS` to `phpunit.xml` as an env variable

**Files to create:**
```
tests/Unit/UI/Tui/Helper/SnapshotRenderer.php
tests/Unit/UI/Tui/SnapshotTestCase.php
```

### Phase 2: Priority Widget Snapshots (Day 2–3)

Add snapshot tests for widgets in priority order:

| Priority | Widget | Reason | # States |
|----------|--------|--------|----------|
| 🔴 P0 | `QuestionWidget` | Simple, good proof of concept | 4 |
| 🔴 P0 | `BashCommandWidget` | Most visual states, highest regression risk | 8 |
| 🔴 P0 | `PermissionPromptWidget` | Interactive state changes (cursor) | 6 |
| 🟡 P1 | `CollapsibleWidget` | Expand/collapse state | 3 |
| 🟡 P1 | `HistoryStatusWidget` | Loading states | 3 |
| 🟡 P1 | `DiscoveryBatchWidget` | Progress states | 3 |
| 🟢 P2 | `BorderFooterWidget` | Layout edge cases | 2 |
| 🟢 P2 | `AnsweredQuestionsWidget` | Content rendering | 2 |
| 🟢 P2 | `SwarmDashboardWidget` | Complex multi-subagent layout | 3 |
| 🟢 P2 | `SettingsWorkspaceWidget` | Multi-panel layout | 4 |
| 🔵 P3 | `AnsiArtWidget` | ASCII art rendering | 2 |
| 🔵 P3 | `PlanApprovalWidget` | Complex interactive widget | 4 |

**Estimated total: ~44 snapshot files**

### Phase 3: Integration & CI (Day 4)

1. Integration snapshot tests for full layouts
2. Responsive width snapshots for P0 widgets
3. GitHub Actions workflow for snapshot tests
4. Manual snapshot update workflow
5. Snapshot count report in CI

### Phase 4: Polish (Day 5)

1. HTML snapshot report generation
2. ANSI-colored diff output
3. Snapshot cleanup command (`--prune-unused-snapshots`)
4. `.gitattributes` to ensure `.snap` files use `text diff` for clean Git diffs

---

## 13. `.gitattributes` Configuration

```
# tests/Unit/UI/Tui/Widget/__snapshots__/*.snap text diff
*.snap text diff linguist-generated
```

This ensures Git treats `.snap` files as text (not binary) and shows meaningful diffs.

---

## 14. Relationship to Existing Tests

Snapshot tests **complement** — not replace — the existing substring-based tests:

| Existing Tests | Snapshot Tests |
|---------------|----------------|
| Assert specific strings appear | Assert exact visual output |
| Test behavior (input handling, callbacks) | Test rendering (layout, wrapping, borders) |
| `assertStringContainsString('running', $content)` | Full screen comparison |
| Fast feedback for logic bugs | Visual regression safety net |

**Migration path:** Keep all existing `*Test.php` files. Add `*SnapshotTest.php` files alongside them. The snapshot tests are a new layer of coverage.

---

## 15. Design Decisions

### Q: Plain text or ANSI snapshots?

**Default: plain text.** Plain text is diffable in Git, readable in code review, and stable across color theme changes. Use styled snapshots only for widgets where color is functionally important (e.g., selected state in `PermissionPromptWidget`).

### Q: One `.snap` file per test, or one per widget?

**One per test.** This mirrors Jest's approach: each `assertMatchesSnapshot()` call writes to its own file. This makes Git diffs precise — you see exactly which state changed.

### Q: Store snapshots in `__snapshots__/` or inline?

**External files in `__snapshots__/`.** Inline snapshots (embedding the expected output in the test method) are tempting but make test files unreadable for 80-column terminal output. External files are cleaner.

### Q: How to handle platform-dependent rendering (UTF-8 box drawing)?

**Assume UTF-8.** KosmoKrator targets modern terminals. All snapshots use Unicode box-drawing characters (`┌─┐│└┘`). If CJK or emoji rendering varies, those snapshots should use `renderCells()` for pixel-level comparison instead.

### Q: What about the Symfony TUI's `Renderer` chrome (borders, padding)?

**Two levels:** Per-widget snapshots test `widget->render()` directly (no chrome). Integration snapshots test through the full `Renderer` pipeline (with chrome). This separates widget content bugs from layout engine bugs.

---

## 16. Files to Create/Modify Summary

### New Files

| File | Purpose |
|------|---------|
| `tests/Unit/UI/Tui/Helper/SnapshotRenderer.php` | Render widgets to ScreenBuffer |
| `tests/Unit/UI/Tui/SnapshotTestCase.php` | PHPUnit trait with assertion + diff |
| `tests/Unit/UI/Tui/Widget/__snapshots__/*.snap` | ~44 golden snapshot files |
| `tests/Unit/UI/Tui/Widget/QuestionWidgetSnapshotTest.php` | QuestionWidget snapshots |
| `tests/Unit/UI/Tui/Widget/BashCommandWidgetSnapshotTest.php` | BashCommandWidget snapshots |
| `tests/Unit/UI/Tui/Widget/PermissionPromptWidgetSnapshotTest.php` | PermissionPrompt snapshots |
| `tests/Unit/UI/Tui/Widget/CollapsibleWidgetSnapshotTest.php` | CollapsibleWidget snapshots |
| `tests/Unit/UI/Tui/Widget/DiscoveryBatchWidgetSnapshotTest.php` | DiscoveryBatch snapshots |
| `tests/Unit/UI/Tui/Widget/HistoryStatusWidgetSnapshotTest.php` | HistoryStatus snapshots |
| `.github/workflows/tui-snapshots.yml` | CI workflow |
| `.gitattributes` | Diff config for `.snap` files |

### Modified Files

| File | Change |
|------|--------|
| `phpunit.xml` | Add `<env name="UPDATE_SNAPSHSETS" value="0" />` |

---

## 17. Risks and Mitigations

| Risk | Mitigation |
|------|-----------|
| Snapshots become stale and get auto-updated blindly | Require PR review for all `.snap` changes; CI fails on uncommitted snapshot diffs |
| Snapshot tests are slow | `ScreenBuffer` is pure PHP — no I/O, no process spawning. ~100 snapshots run in <2s |
| ANSI codes in snapshots make diffs noisy | Default to plain-text snapshots; styled snapshots are opt-in |
| Widgets depend on `Theme` globals that change | Plain-text snapshots strip ANSI codes, making them resilient to color theme changes. Structure changes (borders, wrapping) are still caught |
| Box-drawing characters render differently | Normalize via `ScreenBuffer.getScreen()` which outputs consistent Unicode |
