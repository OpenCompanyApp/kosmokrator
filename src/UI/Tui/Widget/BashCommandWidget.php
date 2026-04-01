<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

class BashCommandWidget extends AbstractWidget implements ToggleableWidgetInterface
{
    private const PREVIEW_LINES = 3;

    private bool $expanded = false;

    private ?string $output = null;

    private bool $success = true;

    public function __construct(
        private string $command,
    ) {}

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
        $this->invalidate();
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->invalidate();
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    public function setResult(string $output, bool $success): void
    {
        $this->output = str_replace("\t", '   ', $output);
        $this->success = $success;
        $this->invalidate();
    }

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
        $statusPrefix = $this->success ? '' : Theme::error().'✗ '.$r;

        foreach ($previewLines as $index => $outputLine) {
            $prefix = $index === 0 ? '└ ' : '  ';
            $line = $statusPrefix."{$output}{$outputLine}{$r}";
            $lines[] = $prefix.$line;
        }

        if (! $this->expanded && count($outputLines) > self::PREVIEW_LINES) {
            $lines[] = "  {$dim}⊛ +".(count($outputLines) - self::PREVIEW_LINES)." lines (ctrl+o to reveal){$r}";
        } elseif ($this->expanded && count($outputLines) > self::PREVIEW_LINES) {
            $lines[] = "  {$dim}⊛ (ctrl+o to collapse){$r}";
        }

        return $this->truncateLines($lines, $cols);
    }

    /**
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
