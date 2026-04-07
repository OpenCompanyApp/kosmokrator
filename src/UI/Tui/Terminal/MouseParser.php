<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

/**
 * Parses SGR-1006 and X10 mouse escape sequences into MouseEvent objects.
 *
 * SGR-1006 format (primary):
 *   \x1b[<B;X;YM   — press/drag/scroll (uppercase M)
 *   \x1b[<B;X;Ym   — release (lowercase m)
 *
 * X10 format (legacy fallback):
 *   \x1b[M Cb Cx Cy — 6-byte sequence, coordinates offset by 32
 *
 * Button code bit layout (SGR):
 *   bit 0: left button
 *   bit 1: middle button
 *   bit 2: shift modifier
 *   bit 3: meta (alt) modifier
 *   bit 4: ctrl modifier
 *   bit 5: motion flag (drag)
 *   bit 6: scroll wheel flag
 *
 * @see docs/plans/tui-overhaul/05-mouse-support/01-mouse-tracking.md
 */
final class MouseParser
{
    /**
     * Parse a raw escape sequence into a MouseEvent, or null if not a mouse sequence.
     */
    public function parse(string $sequence): ?MouseEvent
    {
        // SGR mouse: \x1b[<B;X;YM  or  \x1b[<B;X;Ym
        if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $sequence, $m)) {
            return $this->parseSgr((int) $m[1], (int) $m[2], (int) $m[3], $m[4]);
        }

        // Old-style mouse: \x1b[M + 3 bytes (6-byte total)
        if (6 === \strlen($sequence) && "\x1b[M" === substr($sequence, 0, 3)) {
            return $this->parseX10(
                \ord($sequence[3]),
                \ord($sequence[4]),
                \ord($sequence[5]),
            );
        }

        return null;
    }

    /**
     * Parse an SGR-1006 mouse sequence.
     *
     * @param int    $buttonCode Raw button code from the sequence
     * @param int    $col        1-indexed column
     * @param int    $row        1-indexed row
     * @param string $action     'M' (press/drag/scroll) or 'm' (release)
     */
    private function parseSgr(int $buttonCode, int $col, int $row, string $action): MouseEvent
    {
        // SGR coordinates are 1-indexed; convert to 0-indexed
        $col = max(0, $col - 1);
        $row = max(0, $row - 1);

        $shift = (bool) ($buttonCode & 0x04);
        $alt = (bool) ($buttonCode & 0x08);
        $ctrl = (bool) ($buttonCode & 0x10);

        // Scroll events: bit 6 set (buttonCode >= 64)
        if ($buttonCode >= 64) {
            return new MouseEvent(
                action: ($buttonCode & 0x01) ? MouseAction::ScrollDown : MouseAction::ScrollUp,
                button: MouseButton::None,
                col: $col,
                row: $row,
                shift: $shift,
                alt: $alt,
                ctrl: $ctrl,
            );
        }

        // Release: lowercase 'm'
        if ('m' === $action) {
            return new MouseEvent(
                action: MouseAction::Release,
                button: MouseButton::None,
                col: $col,
                row: $row,
                shift: $shift,
                alt: $alt,
                ctrl: $ctrl,
            );
        }

        // Press or drag
        $lowButton = $buttonCode & 0x03;
        $isMotion = (bool) ($buttonCode & 0x20);

        $button = match ($lowButton) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        return new MouseEvent(
            action: $isMotion ? MouseAction::Drag : MouseAction::Press,
            button: $button,
            col: $col,
            row: $row,
            shift: $shift,
            alt: $alt,
            ctrl: $ctrl,
        );
    }

    /**
     * Parse an X10 (legacy) mouse sequence.
     *
     * X10 only reports button press. Coordinates are offset by 32.
     *
     * @param int $cb Button byte
     * @param int $cx Column byte (offset by 32)
     * @param int $cy Row byte (offset by 32)
     */
    private function parseX10(int $cb, int $cx, int $cy): MouseEvent
    {
        $col = max(0, $cx - 32 - 1);
        $row = max(0, $cy - 32 - 1);

        $lowButton = $cb & 0x03;

        $button = match ($lowButton) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        return new MouseEvent(
            action: MouseAction::Press,
            button: $button,
            col: $col,
            row: $row,
            shift: (bool) ($cb & 0x04),
            alt: (bool) ($cb & 0x08),
            ctrl: (bool) ($cb & 0x10),
        );
    }
}
