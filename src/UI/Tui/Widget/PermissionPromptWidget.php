<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Interactive permission prompt for tool-call approval.
 * Shown when the agent requests a tool that requires user confirmation (Guardian/Argus mode).
 * Displays a preview of the tool call and presents Allow/Always/Guardian/Prometheus/Deny options.
 */
final class PermissionPromptWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private const OPTIONS = [
        ['value' => 'allow', 'label' => 'Allow once', 'description' => 'Execute this tool call'],
        ['value' => 'always', 'label' => 'Always allow', 'description' => 'Allow this tool for the current session'],
        ['value' => 'guardian', 'label' => 'Guardian ◈', 'description' => 'Switch to smart auto-approve'],
        ['value' => 'prometheus', 'label' => 'Prometheus ⚡', 'description' => 'Switch to auto-approve all'],
        ['value' => 'deny', 'label' => 'Deny', 'description' => 'Block this tool call'],
    ];

    private int $selectedIndex = 0;

    /** @var callable(string): void|null Callback invoked with the chosen option value ('allow'|'always'|'guardian'|'prometheus'|'deny'). */
    private $onConfirmCallback = null;

    /** @var callable(): void|null Callback invoked when the user dismisses (Esc). */
    private $onDismissCallback = null;

    /**
     * @param  array{
     *     title: string,
     *     tool_label: string,
     *     summary: string,
     *     sections: list<array{label: string, lines: list<string>}>
     * }  $preview
     */
    public function __construct(
        private readonly string $toolName,
        private readonly array $preview,
    ) {}

    /** Register the callback invoked when the user confirms an option. */
    public function onConfirm(callable $callback): static
    {
        $this->onConfirmCallback = $callback;

        return $this;
    }

    /** Register the callback invoked when the user dismisses the prompt. */
    public function onDismiss(callable $callback): static
    {
        $this->onDismissCallback = $callback;

        return $this;
    }

    /** Handle arrow/Enter/Esc input to navigate and select an approval option. */
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'up')) {
            $this->selectedIndex = ($this->selectedIndex - 1 + count(self::OPTIONS)) % count(self::OPTIONS);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'down')) {
            $this->selectedIndex = ($this->selectedIndex + 1) % count(self::OPTIONS);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'confirm')) {
            ($this->onConfirmCallback)(self::OPTIONS[$this->selectedIndex]['value']);

            return;
        }

        if ($kb->matches($data, 'cancel')) {
            ($this->onDismissCallback)();
        }
    }

    /**
     * Render the bordered tool-call preview and selectable approval options.
     *
     * @param  RenderContext  $context  Terminal dimensions
     * @return list<string>  ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $innerWidth = max(32, $columns - 4);

        $r = Theme::reset();
        $border = Theme::borderAccent();
        $accent = Theme::accent();
        $primary = Theme::primary();
        $white = Theme::white();
        $dim = Theme::dim();
        $info = Theme::info();
        $toolIcon = Theme::toolIcon($this->toolName);

        $lines = [];
        $title = " {$toolIcon} {$this->preview['title']} ";
        $titleFill = max(0, $innerWidth - AnsiUtils::visibleWidth($title) - 1);
        $lines[] = AnsiUtils::truncateToWidth(
            "{$border}┌─{$accent}{$title}{$border}".str_repeat('─', $titleFill)."┐{$r}",
            $columns
        );

        foreach ($this->wrapBlock($this->preview['tool_label'], $innerWidth) as $line) {
            $lines[] = $this->boxLine($line, $innerWidth, $columns, $border, $info, $r);
        }
        foreach ($this->wrapBlock($this->preview['summary'], $innerWidth) as $line) {
            $lines[] = $this->boxLine($line, $innerWidth, $columns, $border, $dim, $r);
        }
        $lines[] = $this->boxLine('', $innerWidth, $columns, $border, '', $r);

        foreach ($this->preview['sections'] as $section) {
            $lines[] = $this->boxLine($section['label'], $innerWidth, $columns, $border, $accent, $r);
            foreach ($section['lines'] as $sectionLine) {
                foreach ($this->wrapBlock($sectionLine, $innerWidth - 2) as $line) {
                    $lines[] = $this->boxLine($line, $innerWidth, $columns, $border, $white, $r, prefix: '  ');
                }
            }
            $lines[] = $this->boxLine('', $innerWidth, $columns, $border, '', $r);
        }

        $lines[] = $this->boxLine('Approval', $innerWidth, $columns, $border, $accent, $r);
        foreach (self::OPTIONS as $index => $option) {
            $selected = $index === $this->selectedIndex;
            $cursor = $selected ? "{$primary}›{$r} " : '  ';
            $labelColor = $selected ? $white : Theme::text();
            $lines[] = $this->boxLine("{$cursor}{$labelColor}{$option['label']}{$r}", $innerWidth, $columns, $border, '', $r);
            $lines[] = $this->boxLine("  {$dim}{$option['description']}{$r}", $innerWidth, $columns, $border, '', $r);
        }

        $lines[] = $this->boxLine('', $innerWidth, $columns, $border, '', $r);
        $lines[] = $this->boxLine("{$dim}Enter confirm  Esc deny{$r}", $innerWidth, $columns, $border, '', $r);
        $lines[] = AnsiUtils::truncateToWidth(
            "{$border}└".str_repeat('─', $innerWidth + 2)."┘{$r}",
            $columns
        );

        return $lines;
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }

    /**
     * @return list<string>
     */
    /** Word-wrap a text block to fit within a given visible width. */
    private function wrapBlock(string $text, int $width): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [''];
        }

        $lines = [];
        foreach (preg_split('/\R/', $trimmed) ?: [] as $paragraph) {
            $current = '';
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];

            foreach ($words as $word) {
                $candidate = $current === '' ? $word : "{$current} {$word}";
                if (mb_strwidth($candidate) > $width && $current !== '') {
                    $lines[] = $current;
                    $current = $word;

                    continue;
                }

                if (mb_strwidth($candidate) > $width) {
                    $lines[] = mb_strimwidth($candidate, 0, $width, '…');
                    $current = '';

                    continue;
                }

                $current = $candidate;
            }

            $lines[] = $current === '' ? '' : $current;
        }

        return $lines;
    }

    /** Render a single boxed line with left/right borders, padding to innerWidth. */
    private function boxLine(
        string $content,
        int $innerWidth,
        int $columns,
        string $border,
        string $color,
        string $reset,
        string $prefix = ''
    ): string {
        $visible = AnsiUtils::visibleWidth($prefix.$content);
        $padding = max(0, $innerWidth - $visible);

        return AnsiUtils::truncateToWidth(
            "{$border}│{$reset} {$prefix}{$color}{$content}{$reset}".str_repeat(' ', $padding)." {$border}│{$reset}",
            $columns
        );
    }
}
