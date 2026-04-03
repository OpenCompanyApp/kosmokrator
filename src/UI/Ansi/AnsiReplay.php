<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Time rewind animation for the :replay command.
 *
 * A clock face draws at center with hands spinning backward, then
 * memory frames of code snippets scroll in reverse through a sepia
 * filter, culminating in full-color title reveal.
 */
class AnsiReplay implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Sepia tone */
    private const SEPIA_R = 180;

    private const SEPIA_G = 150;

    private const SEPIA_B = 100;

    /** Full color cyan */
    private const CYAN_R = 100;

    private const CYAN_G = 220;

    private const CYAN_B = 255;

    /** Code snippets for memory frames — fragments that evoke source code */
    private const CODE_FRAGMENTS = [
        'function init() {',
        'if ($ready) {',
        '$result = [];',
        'return $this->run();',
        'for ($i = 0; ...) {',
        'class Agent {',
        'yield $value;',
        '} catch (\\Ex',
        'fn ($x) => $x',
        '->dispatch($ev',
        'use Illuminate',
        'private array',
        '$config[\'key\']',
        'match ($type) {',
        'echo $output;',
        '// TODO: refac',
        'throw new \\Run',
        'public static',
        'implements Int',
        'readonly string',
    ];

    /**
     * Run the full time-rewind animation (~2.5s).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseClock();
        $this->phaseRewind();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Clock (~0.8s).
     *
     * Draw a simple clock face at center using a circle of dots.
     * Clock hands spin backward (counter-clockwise), accelerating
     * as time rewinds faster and faster.
     */
    private function phaseClock(): void
    {
        $r = Theme::reset();
        $sepia = Theme::rgb(self::SEPIA_R, self::SEPIA_G, self::SEPIA_B);
        $dimSepia = Theme::rgb(100, 85, 55);
        $brightSepia = Theme::rgb(220, 190, 130);

        // Clock radius (account for terminal character aspect ratio ~2:1)
        $clockRadius = min(6, (int) ($this->termHeight * 0.2));
        $clockRadiusH = $clockRadius * 2; // Horizontal stretch for aspect ratio

        // Draw the clock face (circle of dots)
        $segments = 24;
        $facePoints = [];
        for ($i = 0; $i < $segments; $i++) {
            $angle = ($i / $segments) * 2 * M_PI;
            $col = $this->cx + (int) ($clockRadiusH * cos($angle));
            $row = $this->cy + (int) ($clockRadius * sin($angle));

            if ($col >= 1 && $col < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                // Mark hour positions with brighter dots
                $isHour = ($i % 2 === 0);
                $color = $isHour ? $sepia : $dimSepia;
                $char = $isHour ? '○' : '·';
                echo Theme::moveTo($row, $col).$color.$char.$r;
                $facePoints[] = ['row' => $row, 'col' => $col];
            }
        }

        // Center dot
        echo Theme::moveTo($this->cy, $this->cx).$brightSepia.'◉'.$r;
        usleep(100000);

        // Spin hands backward — accelerating counter-clockwise
        $totalSteps = 20;
        $handLength = $clockRadius - 1;
        $handLengthH = $clockRadiusH - 2;

        for ($step = 0; $step < $totalSteps; $step++) {
            // Erase previous hand cells
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' '.$r;
                }
            }
            $this->prevCells = [];

            // Acceleration: hands spin faster as we go
            $progress = $step / $totalSteps;
            $accel = 1.0 + $progress * 3.0; // 1x → 4x speed
            // Counter-clockwise: negative angle, starting from 12 o'clock (-PI/2)
            $minuteAngle = -M_PI / 2 - ($step * 0.5 * $accel);
            $hourAngle = -M_PI / 2 - ($step * 0.15 * $accel);

            // Draw minute hand (longer)
            $this->drawHand($minuteAngle, $handLength, $handLengthH, $brightSepia, $r);

            // Draw hour hand (shorter)
            $shortLen = max(1, (int) ($handLength * 0.6));
            $shortLenH = max(1, (int) ($handLengthH * 0.6));
            $this->drawHand($hourAngle, $shortLen, $shortLenH, $sepia, $r);

            // Keep center dot
            echo Theme::moveTo($this->cy, $this->cx).$brightSepia.'◉'.$r;

            // Redraw any face points that got erased
            foreach ($facePoints as $fp) {
                if ($fp['row'] >= 1 && $fp['row'] <= $this->termHeight && $fp['col'] >= 1 && $fp['col'] < $this->termWidth) {
                    echo Theme::moveTo($fp['row'], $fp['col']).$dimSepia.'·'.$r;
                }
            }

            // Frame timing: accelerating, so shorter sleep as we progress
            $frameTime = (int) (50000 * (1.0 - $progress * 0.5));
            usleep($frameTime);
        }

        // Final erase
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' '.$r;
            }
        }
        $this->prevCells = [];

        usleep(80000);
    }

    /**
     * Draw a clock hand from center at a given angle.
     *
     * @param  float  $angle  Angle in radians (0 = right, -PI/2 = up)
     * @param  int  $lengthV  Vertical reach (rows)
     * @param  int  $lengthH  Horizontal reach (cols)
     * @param  string  $color  ANSI color escape
     * @param  string  $reset  ANSI reset escape
     */
    private function drawHand(float $angle, int $lengthV, int $lengthH, string $color, string $reset): void
    {
        $steps = max($lengthV, $lengthH);
        for ($s = 1; $s <= $steps; $s++) {
            $t = $s / $steps;
            $col = $this->cx + (int) round($lengthH * $t * cos($angle));
            $row = $this->cy + (int) round($lengthV * $t * sin($angle));

            if ($col >= 1 && $col < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                // Choose character based on angle
                $absAngle = abs(fmod($angle, M_PI));
                if ($absAngle < M_PI / 6 || $absAngle > 5 * M_PI / 6) {
                    $char = '─';
                } elseif ($absAngle > M_PI / 3 && $absAngle < 2 * M_PI / 3) {
                    $char = '│';
                } elseif (cos($angle) * sin($angle) > 0) {
                    $char = '╲';
                } else {
                    $char = '╱';
                }

                echo Theme::moveTo($row, $col).$color.$char.$reset;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
        }
    }

    /**
     * Phase 2 — Rewind (~0.9s).
     *
     * Screen fills with "memory frames" — brief flashes of code snippets
     * in sepia tone, scrolling backward (bottom-to-top). Each frame is a
     * fragment of source code that appears and scrolls upward, accelerating
     * as the rewind intensifies.
     */
    private function phaseRewind(): void
    {
        $r = Theme::reset();
        $totalSteps = 22;
        $snippets = self::CODE_FRAGMENTS;

        // Pre-generate frame positions: each snippet placed at a random column
        $frames = [];
        for ($i = 0; $i < $totalSteps * 2; $i++) {
            $col = mt_rand(2, max(3, $this->termWidth - 20));
            $snippetIdx = $i % count($snippets);
            $frames[] = [
                'col' => $col,
                'text' => $snippets[$snippetIdx],
                'startRow' => $this->termHeight + mt_rand(1, 5), // Start below screen
            ];
        }

        for ($step = 0; $step < $totalSteps; $step++) {
            echo Theme::clearScreen();
            $progress = $step / $totalSteps;

            // Sepia → slightly brighter as rewind intensifies
            $intensity = 0.5 + 0.5 * $progress;
            $cr = (int) (self::SEPIA_R * $intensity);
            $cg = (int) (self::SEPIA_G * $intensity);
            $cb = (int) (self::SEPIA_B * $intensity);

            // Scroll speed accelerates
            $scrollSpeed = 1.5 + $progress * 3.5;

            // Draw visible frames scrolling upward
            foreach ($frames as $idx => &$frame) {
                // Each frame scrolls upward from its start position
                $elapsed = max(0, $step - (int) ($idx * 0.5));
                $row = (int) ($frame['startRow'] - $elapsed * $scrollSpeed);

                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                $col = $frame['col'];
                $text = $frame['text'];

                // Fade based on position: center of screen is brightest
                $verticalFade = 1.0 - abs($row - $this->cy) / max(1, $this->cy);
                $verticalFade = max(0.2, $verticalFade);

                $fr = (int) ($cr * $verticalFade);
                $fg = (int) ($cg * $verticalFade);
                $fb = (int) ($cb * $verticalFade);

                // Draw text character by character, clipping at screen edge
                $chars = mb_str_split($text);
                foreach ($chars as $ci => $char) {
                    $charCol = $col + $ci;
                    if ($charCol >= 1 && $charCol < $this->termWidth) {
                        echo Theme::moveTo($row, $charCol)
                            .Theme::rgb($fr, $fg, $fb).$char.$r;
                    }
                }
            }
            unset($frame);

            // Occasional "glitch" lines — horizontal scan artifacts
            if ($step % 4 === 0) {
                $glitchRow = mt_rand(1, $this->termHeight);
                $glitchWidth = mt_rand(5, 15);
                $glitchCol = mt_rand(1, max(2, $this->termWidth - $glitchWidth));
                $glitchColor = Theme::rgb(
                    (int) (self::SEPIA_R * 0.3),
                    (int) (self::SEPIA_G * 0.3),
                    (int) (self::SEPIA_B * 0.3)
                );
                for ($g = 0; $g < $glitchWidth; $g++) {
                    $gc = $glitchCol + $g;
                    if ($gc >= 1 && $gc < $this->termWidth && $glitchRow >= 1 && $glitchRow <= $this->termHeight) {
                        echo Theme::moveTo($glitchRow, $gc).$glitchColor.'░'.$r;
                    }
                }
            }

            usleep((int) (900000 / $totalSteps));
        }

        usleep(80000);
    }

    /**
     * Phase 3 — Title (~0.8s).
     *
     * Screen clears. "R E P L A Y" fades from sepia to full cyan/white.
     * Subtitle types out with the rewind symbol.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'R E P L A Y';
        $subtitle = "\u{21BA} Timeline restored \u{21BA}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade from sepia → cyan → white
        $gradient = [
            [(int) (self::SEPIA_R * 0.3), (int) (self::SEPIA_G * 0.3), (int) (self::SEPIA_B * 0.3)],
            [(int) (self::SEPIA_R * 0.6), (int) (self::SEPIA_G * 0.6), (int) (self::SEPIA_B * 0.6)],
            [self::SEPIA_R, self::SEPIA_G, self::SEPIA_B],
            [150, 175, 140],  // transition
            [120, 190, 200],  // shift to cyan
            [self::CYAN_R, self::CYAN_G, self::CYAN_B],
            [160, 235, 255],
            [220, 245, 255],
            [255, 255, 255],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(50000);
        }

        // Subtitle typeout in cyan
        usleep(100000);
        $cyan = Theme::rgb(self::CYAN_R, self::CYAN_G, self::CYAN_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $cyan.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
