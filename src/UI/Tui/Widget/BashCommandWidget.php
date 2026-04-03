<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Shows a bash command and its captured output in a collapsible block.
 *
 * Collapsed (default): icon + truncated command on one line, up to 2 output preview lines.
 * Expanded: full command in a │ box with all output visible.
 * Failures auto-expand to ensure errors are visible.
 */
class BashCommandWidget extends AbstractWidget implements ToggleableWidgetInterface
{
    private const PREVIEW_LINES = 2;

    /** Whether the output section is fully expanded. */
    private bool $expanded = false;

    /** Captured command output (null until the command finishes). */
    private ?string $output = null;

    /** Whether the command exited successfully. */
    private bool $success = true;

    public function __construct(
        private string $command,
    ) {}

    /** Toggle the output section between collapsed and expanded. */
    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
        $this->invalidate();
    }

    /** Explicitly set the expanded state. */
    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->invalidate();
    }

    /** Check whether the output section is currently expanded. */
    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    /**
     * Store the command result after execution completes.
     * Failures auto-expand so errors are immediately visible.
     */
    public function setResult(string $output, bool $success): void
    {
        $this->output = str_replace("\t", '   ', $output);
        $this->success = $success;
        if (! $success) {
            $this->expanded = true;
        }
        $this->invalidate();
    }

    /**
     * Render the bash block: compact when collapsed, full layout when expanded.
     */
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $gold = Theme::accent();
        $r = Theme::reset();
        $dim = Theme::dim();
        $text = Theme::text();
        $outputColor = Theme::rgb(155, 155, 165);
        $icon = Theme::toolIcon('bash');

        if ($this->expanded) {
            return $this->renderExpanded($cols, $gold, $r, $dim, $text, $outputColor, $icon);
        }

        return $this->renderCollapsed($cols, $gold, $r, $dim, $text, $outputColor, $icon);
    }

    /**
     * Compact view: icon + truncated command, then 2 output preview lines.
     *
     * @return string[]
     */
    private function renderCollapsed(int $cols, string $gold, string $r, string $dim, string $text, string $outputColor, string $icon): array
    {
        $command = $this->stripCwdPrefix($this->command);
        $headerWidth = max(20, $cols - 3); // icon + space + command

        if ($command === '') {
            $headerLine = "{$gold}{$icon}{$r} {$dim}(shell){$r}";
        } elseif (mb_strlen($command) > $headerWidth) {
            $headerLine = "{$gold}{$icon}{$r} ".mb_substr($command, 0, $headerWidth - 1)."…";
        } else {
            $headerLine = "{$gold}{$icon}{$r} {$command}";
        }

        $lines = [$headerLine];

        if ($this->output === null) {
            $lines[] = "└ {$dim}running...{$r}";

            return $this->truncateLines($lines, $cols);
        }

        $outputLines = explode("\n", $this->output);
        if ($outputLines === ['']) {
            $outputLines = [$this->success ? "{$dim}(no output){$r}" : Theme::error().'command failed'.$r];
        }

        $previewLines = array_slice($outputLines, 0, self::PREVIEW_LINES);
        $statusPrefix = $this->success ? '' : Theme::error().'✗ '.$r;

        foreach ($previewLines as $index => $outputLine) {
            $prefix = $index === 0 ? '└ ' : '  ';
            $lines[] = $prefix.$statusPrefix."{$outputColor}{$outputLine}{$r}";
        }

        if (count($outputLines) > self::PREVIEW_LINES) {
            $remaining = count($outputLines) - self::PREVIEW_LINES;
            $lines[] = "  {$dim}⊛ +{$remaining} lines (ctrl+o to reveal){$r}";
        }

        return $this->truncateLines($lines, $cols);
    }

    /**
     * Full expanded view: icon + label header, command in │ box, all output, collapse hint.
     *
     * @return string[]
     */
    private function renderExpanded(int $cols, string $gold, string $r, string $dim, string $text, string $outputColor, string $icon): array
    {
        $contentWidth = max(20, $cols - 4);

        $lines = [
            "{$gold}{$icon} Bash{$r}",
        ];

        foreach ($this->wrapCommand($contentWidth) as $commandLine) {
            $lines[] = "│ {$text}{$commandLine}{$r}";
        }

        if ($this->output === null) {
            $lines[] = "└ {$dim}running...{$r}";

            return $this->truncateLines($lines, $cols);
        }

        $outputLines = explode("\n", $this->output);
        if ($outputLines === ['']) {
            $outputLines = [$this->success ? "{$dim}(no output){$r}" : Theme::error().'command failed'.$r];
        }
        $statusPrefix = $this->success ? '' : Theme::error().'✗ '.$r;

        foreach ($outputLines as $index => $outputLine) {
            $prefix = $index === 0 ? '└ ' : '  ';
            $lines[] = $prefix.$statusPrefix."{$outputColor}{$outputLine}{$r}";
        }

        $lines[] = "  {$dim}⊛ (ctrl+o to collapse){$r}";

        return $this->truncateLines($lines, $cols);
    }

    /**
     * Strip leading `cd /absolute/path && ` prefix from the command for display.
     * The agent already operates in the working directory, so this is pure noise.
     */
    private function stripCwdPrefix(string $command): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $command;
        }

        $prefix = "cd {$cwd} && ";
        if (str_starts_with($command, $prefix)) {
            return substr($command, strlen($prefix));
        }

        // Also handle quoted variant: cd "/path" && or cd '/path' &&
        foreach (['"', "'"] as $quote) {
            $quotedPrefix = "cd {$quote}{$cwd}{$quote} && ";
            if (str_starts_with($command, $quotedPrefix)) {
                return substr($command, strlen($quotedPrefix));
            }
        }

        return $command;
    }

    /**
     * Word-wrap the command string to fit within the given width.
     *
     * @return string[]
     */
    private function wrapCommand(int $width): array
    {
        $wrapped = [];
        foreach (explode("\n", $this->command) as $line) {
            $segments = explode("\n", wordwrap($line, $width, "\n", true));
            foreach ($segments as $segment) {
                $wrapped[] = $segment;
            }
        }

        return $wrapped === [] ? [''] : $wrapped;
    }

    /**
     * Truncate every rendered line that exceeds the terminal column width.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private function truncateLines(array $lines, int $cols): array
    {
        foreach ($lines as $index => $line) {
            if (AnsiUtils::visibleWidth($line) > $cols) {
                $lines[$index] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
        }

        return $lines;
    }
}
