<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

class PlanApprovalWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private const PERMISSIONS = [
        ['id' => 'guardian', 'label' => 'Guardian ◈', 'hint' => 'smart', 'color' => "\033[38;2;180;180;200m"],
        ['id' => 'argus', 'label' => 'Argus ◉', 'hint' => 'strict', 'color' => "\033[38;2;100;140;200m"],
        ['id' => 'prometheus', 'label' => 'Prometheus ⚡', 'hint' => 'auto', 'color' => "\033[38;2;255;200;80m"],
    ];

    private const CONTEXTS = [
        ['id' => 'keep', 'label' => 'keep context'],
        ['id' => 'compact', 'label' => 'compact'],
        ['id' => 'clear', 'label' => 'clear'],
    ];

    /** 0 = Implement, 1 = permission toggle, 2 = context toggle, 3 = Dismiss */
    private int $selectedRow = 0;

    private int $permissionIndex = 0;

    private int $contextIndex = 0;

    public function __construct(string $currentPermissionMode)
    {
        foreach (self::PERMISSIONS as $i => $perm) {
            if ($perm['id'] === $currentPermissionMode) {
                $this->permissionIndex = $i;
                break;
            }
        }
    }

    /** @var callable|null */
    private $onConfirmCallback = null;

    /** @var callable|null */
    private $onDismissCallback = null;

    public function onConfirm(callable $callback): static
    {
        $this->onConfirmCallback = $callback;

        return $this;
    }

    public function onDismiss(callable $callback): static
    {
        $this->onDismissCallback = $callback;

        return $this;
    }

    public function getPermissionId(): string
    {
        return self::PERMISSIONS[$this->permissionIndex]['id'];
    }

    public function getContextId(): string
    {
        return self::CONTEXTS[$this->contextIndex]['id'];
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        // Up
        if ($kb->matches($data, 'up')) {
            $this->selectedRow = $this->selectedRow === 0 ? 3 : $this->selectedRow - 1;
            $this->invalidate();

            return;
        }

        // Down
        if ($kb->matches($data, 'down')) {
            $this->selectedRow = $this->selectedRow === 3 ? 0 : $this->selectedRow + 1;
            $this->invalidate();

            return;
        }

        // Left/Right — cycle toggles on rows 1 and 2
        if ($kb->matches($data, 'left')) {
            if ($this->selectedRow === 1) {
                $this->permissionIndex = ($this->permissionIndex - 1 + count(self::PERMISSIONS)) % count(self::PERMISSIONS);
                $this->invalidate();
            } elseif ($this->selectedRow === 2) {
                $this->contextIndex = ($this->contextIndex - 1 + count(self::CONTEXTS)) % count(self::CONTEXTS);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'right')) {
            if ($this->selectedRow === 1) {
                $this->permissionIndex = ($this->permissionIndex + 1) % count(self::PERMISSIONS);
                $this->invalidate();
            } elseif ($this->selectedRow === 2) {
                $this->contextIndex = ($this->contextIndex + 1) % count(self::CONTEXTS);
                $this->invalidate();
            }

            return;
        }

        // Enter — confirm
        if ($kb->matches($data, 'confirm')) {
            if ($this->selectedRow === 3) {
                ($this->onDismissCallback)();
            } else {
                ($this->onConfirmCallback)();
            }

            return;
        }

        // Escape — dismiss
        if ($kb->matches($data, 'cancel')) {
            ($this->onDismissCallback)();
        }
    }

    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $dim = "\033[38;5;248m"; // lighter dim so text stays readable
        $white = Theme::white();
        $plan = "\033[38;2;160;120;255m"; // plan mode purple
        $border = Theme::borderPlan();
        $columns = $context->getColumns();

        $perm = self::PERMISSIONS[$this->permissionIndex];
        $ctx = self::CONTEXTS[$this->contextIndex];
        $permModeColor = $perm['color'];

        // Full width box (box takes 4 chars: "│ " + " │")
        $innerW = $columns - 4;
        $padLine = fn (string $content) => $this->padToWidth($content, $innerW, $r);

        // Build inner rows — implement group (rows 0-2) stays bright together
        $inImpl = $this->selectedRow <= 2;
        $cursor = fn (int $row) => $this->selectedRow === $row ? "{$plan}›{$r} " : '  ';

        $implLabel = $inImpl ? $white : $dim;
        $row0 = "{$cursor(0)}{$implLabel}1. Implement{$r}";

        $arrowColor1 = $this->selectedRow === 1 ? $white : ($inImpl ? $dim : "\033[38;5;243m");
        $row1 = "    {$cursor(1)}{$arrowColor1}◄{$r} {$permModeColor}{$perm['label']}{$r} {$arrowColor1}►{$r}  {$dim}{$perm['hint']}{$r}";

        $arrowColor2 = $this->selectedRow === 2 ? $white : ($inImpl ? $dim : "\033[38;5;243m");
        $ctxLabelColor = $this->selectedRow === 2 ? $white : ($inImpl ? $dim : "\033[38;5;243m");
        $row2 = "    {$cursor(2)}{$arrowColor2}◄{$r} {$ctxLabelColor}{$ctx['label']}{$r} {$arrowColor2}►{$r}";

        $dismissColor = $this->selectedRow === 3 ? $white : $dim;
        $row3 = "{$cursor(3)}{$dismissColor}2. Dismiss{$r}";

        // Build bordered box — full terminal width
        $lines = [];
        $lines[] = AnsiUtils::truncateToWidth("{$border}┌─{$plan} Plan complete {$border}" . str_repeat('─', max(0, $innerW - 16)) . "┐{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}│{$r} {$padLine($row0)} {$border}│{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}│{$r} {$padLine($row1)} {$border}│{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}│{$r} {$padLine($row2)} {$border}│{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}│{$r} {$padLine('')}  {$border}│{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}│{$r} {$padLine($row3)} {$border}│{$r}", $columns);
        $lines[] = AnsiUtils::truncateToWidth("{$border}└" . str_repeat('─', $innerW + 2) . "┘{$r}", $columns);

        return $lines;
    }

    /**
     * Pad a string with ANSI escapes to a visible width.
     */
    private function padToWidth(string $text, int $width, string $reset): string
    {
        $visible = AnsiUtils::visibleWidth($text);
        if ($visible >= $width) {
            return $text;
        }

        return $text . str_repeat(' ', $width - $visible);
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'left' => [Key::LEFT],
            'right' => [Key::RIGHT],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }
}
