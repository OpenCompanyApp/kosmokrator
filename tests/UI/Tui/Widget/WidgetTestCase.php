<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\UI\Tui\Widget;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Base test class for all TUI widget unit tests.
 *
 * Provides rendering helpers, input simulation, and assertion methods
 * for testing widget output at various terminal sizes.
 *
 * Every widget test class extends this base.
 */
abstract class WidgetTestCase extends TestCase
{
    // ─── Rendering ──────────────────────────────────────────────────

    /**
     * Render a widget at the given dimensions and return plain-text screen lines.
     *
     * Uses ScreenBuffer to normalize ANSI output into a stable cell grid.
     * Returns plain-text lines (ANSI codes stripped) for content assertions.
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns            Terminal width (default 80)
     * @param int $rows               Terminal height (default 24)
     * @return string[]               One string per screen row, trailing spaces trimmed
     */
    protected function renderWidget(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): array {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        $buffer = new ScreenBuffer($columns, $rows);
        $buffer->write(implode("\r\n", $lines));

        return explode("\n", $buffer->getScreen());
    }

    /**
     * Render a widget and return ANSI-styled lines.
     *
     * Use when testing color/style correctness.
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns            Terminal width
     * @param int $rows               Terminal height
     * @return string[]               Lines with ANSI escape codes preserved
     */
    protected function renderWidgetStyled(
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
     * Render a widget and return the raw cell array.
     *
     * Use for pixel-level assertions (e.g., "cell at row 3, col 5 is '✓'").
     *
     * @param AbstractWidget $widget  The widget to render
     * @param int $columns            Terminal width
     * @param int $rows               Terminal height
     * @return array<int, array<int, array{char: string, style: string}>>
     */
    protected function renderWidgetCells(
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
     * Use for integration tests that need terminal behavior (resize,
     * input routing, cursor movement, etc.)
     */
    protected function renderViaVirtualTerminal(
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

    // ─── Assertions ─────────────────────────────────────────────────

    /**
     * Assert that the rendered output exactly matches the expected lines.
     *
     * Compares plain-text output line by line. Trailing whitespace is ignored.
     *
     * @param string[] $expectedLines  The expected output lines
     * @param AbstractWidget $widget   The widget to render and check
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
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
     * @param string $needle           The substring to search for
     * @param AbstractWidget $widget   The widget to render and check
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
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
     *
     * @param string $needle           The substring that must not appear
     * @param AbstractWidget $widget   The widget to render and check
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
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
     * @param AbstractWidget $widget   The widget to render and check
     * @param string $sequence         The ANSI escape sequence to search for
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
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
     * @param int $row                 Row index (0-based)
     * @param int $col                 Column index (0-based)
     * @param string $expectedChar     Expected character at that cell
     * @param AbstractWidget $widget   The widget to render
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
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
     * @param AbstractWidget $widget   The widget to render
     * @param int $columns             Terminal width
     * @param int $rows                Terminal height
     */
    protected function assertNoLineExceedsWidth(
        AbstractWidget $widget,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $context = new RenderContext($columns, $rows);
        $lines = $widget->render($context);

        foreach ($lines as $i => $line) {
            $visibleWidth = AnsiUtils::visibleWidth($line);
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

    // ─── Input Simulation ───────────────────────────────────────────

    /**
     * Simulate keyboard input on a focusable widget.
     *
     * Sends raw input bytes through the VirtualTerminal's StdinBuffer
     * for proper sequence parsing (arrow keys, Ctrl+key, etc.).
     *
     * Returns the terminal for further assertions.
     *
     * @param FocusableInterface $widget  The widget to send input to
     * @param string $input               Raw input bytes (use Key constants or escape sequences)
     * @param int $columns                Terminal width
     * @param int $rows                   Terminal height
     * @return VirtualTerminal            The terminal with captured output
     */
    protected function simulateInput(
        FocusableInterface $widget,
        string $input,
        int $columns = 80,
        int $rows = 24,
    ): VirtualTerminal {
        $terminal = new VirtualTerminal($columns, $rows);
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
     * @param string[] $inputs            Array of raw input bytes
     * @param int $columns                Terminal width
     * @param int $rows                   Terminal height
     */
    protected function sendKeys(
        FocusableInterface $widget,
        array $inputs,
        int $columns = 80,
        int $rows = 24,
    ): void {
        $terminal = new VirtualTerminal($columns, $rows);
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

    // ─── Resize Simulation ──────────────────────────────────────────

    /**
     * Simulate a terminal resize and re-render the widget at the new size.
     *
     * @param AbstractWidget $widget  The widget to test
     * @param int $fromCols           Original width
     * @param int $fromRows           Original height
     * @param int $toCols             New width
     * @param int $toRows             New height
     * @return string[]               Rendered lines at the new size
     */
    protected function renderAfterResize(
        AbstractWidget $widget,
        int $fromCols,
        int $fromRows,
        int $toCols,
        int $toRows,
    ): array {
        // First render at original size to establish state
        $this->renderWidget($widget, $fromCols, $fromRows);

        // Render at new size
        return $this->renderWidget($widget, $toCols, $toRows);
    }

    // ─── Data Providers ─────────────────────────────────────────────

    /**
     * Standard test dimension matrix.
     *
     * @return array<string, array{int, int}>
     */
    public static function sizeProvider(): array
    {
        return [
            'narrow (60×20)'     => [60, 20],
            'default (80×24)'    => [80, 24],
            'wide (120×30)'      => [120, 30],
            'ultrawide (200×50)' => [200, 50],
        ];
    }

    /**
     * Minimum viable sizes for most widgets.
     *
     * @return array<string, array{int, int}>
     */
    public static function minSizeProvider(): array
    {
        return [
            'minimal' => [40, 12],
            'narrow'  => [50, 16],
        ];
    }

    // ─── Internal Helpers ───────────────────────────────────────────

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

        $diff .= str_repeat('─', 60);

        return $diff;
    }
}
