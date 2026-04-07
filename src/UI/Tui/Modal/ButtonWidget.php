<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Modal;

use Kosmokrator\UI\Theme;

/**
 * A single button within a DialogWidget's button row.
 *
 * Renders as `[ Label ]` with focus highlighting:
 * - Unfocused: `[ Label ]` in dim colors
 * - Focused:   `[▸ Label ]` with highlighted border and text
 *
 * Supports semantic variants:
 * - DEFAULT: standard button
 * - PRIMARY: highlighted (confirm action)
 * - DANGER:  red-highlighted (destructive action)
 */
final class ButtonWidget
{
    public const VARIANT_DEFAULT = 'default';
    public const VARIANT_PRIMARY = 'primary';
    public const VARIANT_DANGER = 'danger';

    /** Button label text */
    private string $label;

    /** Value returned when this button is clicked */
    private string $value;

    /** Visual variant */
    private string $variant;

    /**
     * @param string $label   Display text
     * @param string $value   Return value when activated
     * @param string $variant One of VARIANT_* constants
     */
    public function __construct(
        string $label,
        string $value,
        string $variant = self::VARIANT_DEFAULT,
    ) {
        $this->label = $label;
        $this->value = $value;
        $this->variant = $variant;
    }

    // --- Convenience factories ---

    public static function confirm(string $label = 'Confirm'): self
    {
        return new self($label, DialogResult::Confirmed->value, self::VARIANT_PRIMARY);
    }

    public static function cancel(string $label = 'Cancel'): self
    {
        return new self($label, DialogResult::Cancelled->value, self::VARIANT_DEFAULT);
    }

    public static function ok(string $label = 'OK'): self
    {
        return new self($label, DialogResult::Acknowledged->value, self::VARIANT_PRIMARY);
    }

    public static function danger(string $label, string $value = 'danger'): self
    {
        return new self($label, $value, self::VARIANT_DANGER);
    }

    // --- Accessors ---

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    /**
     * Get the visible width of this button when rendered (including brackets and spacing).
     */
    public function getVisibleWidth(): int
    {
        return mb_strwidth($this->label) + 4; // '[ ' + label + ' ]'
    }

    // --- Rendering ---

    /**
     * Render this button as an inline ANSI string (for embedding in a button row).
     *
     * @param bool $focused Whether this button has focus
     * @return string ANSI-formatted button string
     */
    public function renderInline(bool $focused): string
    {
        $r = Theme::reset();

        if ($focused) {
            return match ($this->variant) {
                self::VARIANT_PRIMARY => $this->renderFocused(Theme::accent(), '▸'),
                self::VARIANT_DANGER => $this->renderFocused(Theme::error(), '▸'),
                default => $this->renderFocused(Theme::white(), '▸'),
            };
        }

        return match ($this->variant) {
            self::VARIANT_PRIMARY => "\033[38;2;180;140;50m[ {$this->label} ]{$r}",
            self::VARIANT_DANGER => "\033[38;2;160;60;50m[ {$this->label} ]{$r}",
            default => Theme::dim() . "[ {$this->label} ]{$r}",
        };
    }

    /**
     * Render a focused button with the given highlight color.
     */
    private function renderFocused(string $color, string $cursor): string
    {
        $r = Theme::reset();
        $white = Theme::white();

        return "{$color}[{$r} {$cursor}{$white} {$this->label} {$color}]{$r}";
    }
}
