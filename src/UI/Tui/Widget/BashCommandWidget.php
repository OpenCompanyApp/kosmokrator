<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Shows a bash command and its captured output in a collapsible block. Displayed during
 * task execution whenever the agent runs a shell command.
 */
class BashCommandWidget extends AbstractWidget implements ToggleableWidgetInterface
{
    private const PREVIEW_LINES = 3;

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
     */
    public function setResult(string $output, bool $success): void
    {
        $this->output = str_replace("\t", '   ', $output);
        $this->success = $success;
        $this->invalidate();
    }

    /**
     * Render the command header, wrapped command text, and (optionally truncated) output.
     */
    public function render(RenderContext $context): array
    {
        $gold = Theme::accent();
        $r = Theme::reset();
        $dim = Theme::dim();
        $text = Theme::text();
        $output = Theme::rgb(155, 155, 165);
        $cols = $context->getColumns();
        $contentWidth = max(20, $cols - 4);

        $lines = [
            "{$gold}".Theme::toolIcon('bash').' Bash'."{$r}",
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
        $previewLines = $this->expanded ? $outputLines : array_slice($outputLines, 0, self::PREVIEW_LINES);
        // Prepend a failure marker on the first output line when the command failed
        $statusPrefix = $this->success ? '' : Theme::error().'✗ '.$r;

        foreach ($previewLines as $index => $outputLine) {
            $prefix = $index === 0 ? '└ ' : '  ';
            $line = $statusPrefix."{$output}{$outputLine}{$r}";
            $lines[] = $prefix.$line;
        }

        if (! $this->expanded && count($outputLines) > self::PREVIEW_LINES) {
            // Hint to expand when output is truncated
            $lines[] = "  {$dim}⊛ +".(count($outputLines) - self::PREVIEW_LINES)." lines (ctrl+o to reveal){$r}";
        } elseif ($this->expanded && count($outputLines) > self::PREVIEW_LINES) {
            $lines[] = "  {$dim}⊛ (ctrl+o to collapse){$r}";
        }

        return $this->truncateLines($lines, $cols);
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
