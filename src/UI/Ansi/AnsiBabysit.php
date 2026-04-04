<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Guardian eye animation for the :babysit power command.
 *
 * A watchful sentinel eye opens, scans the horizon, pulses with a
 * steady heartbeat rhythm, then reveals the command title. Conveys
 * calm, persistent vigilance.
 */
class AnsiBabysit implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Amber eye */
    private const EYE_R = 255;

    private const EYE_G = 200;

    private const EYE_B = 80;

    /** Green all-clear */
    private const GREEN_R = 80;

    private const GREEN_G = 220;

    private const GREEN_B = 100;

    /** Soft scan white */
    private const SCAN_R = 200;

    private const SCAN_G = 210;

    private const SCAN_B = 220;

    /**
     * Run the full guardian eye animation (~3s).
     */
    public function animate(): void
    {
        $this->termWidth = TerminalSize::cols();
        $this->termHeight = TerminalSize::lines();
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(function () {
            echo Theme::showCursor();
        });

        $this->installSignalHandler();

        try {
            $this->phaseEyeOpen();
            $this->phaseScan();
            $this->phasePulse();
            $this->phaseTitle();

            usleep(400000);
            echo Theme::clearScreen();
            echo Theme::showCursor();
        } catch (IntroSkippedException) {
            // Animation skipped by user
        } finally {
            $this->restoreSignalHandler();
            TerminalSize::reset();
        }
    }

    /**
     * Phase 1 — Eye Open (~0.7s).
     *
     * A stylized eye shape draws at center. Top and bottom lid arcs
     * separate to reveal the iris ring and pupil. Amber tones throughout.
     */
    private function phaseEyeOpen(): void
    {
        $r = Theme::reset();

        // Eye ASCII art frames — lids progressively open
        // Each frame: array of [rowOffset, string] from center
        $frames = [
            // Frame 0: fully closed — just a line
            [
                [0, '────────────────'],
            ],
            // Frame 1: slightly open
            [
                [-1, '    ──────      '],
                [0,  '  ─(   ◉   )─  '],
                [1,  '    ──────      '],
            ],
            // Frame 2: half open
            [
                [-2, '     ╭────╮     '],
                [-1, '   ╭─      ─╮   '],
                [0,  '  (    ◉    )  '],
                [1,  '   ╰─      ─╯   '],
                [2,  '     ╰────╯     '],
            ],
            // Frame 3: fully open
            [
                [-3, '      ╭──────╮      '],
                [-2, '   ╭──        ──╮   '],
                [-1, '  ╱    ╭────╮    ╲  '],
                [0,  ' (    ( ◉◉ )    ) '],
                [1,  '  ╲    ╰────╯    ╱  '],
                [2,  '   ╰──        ──╯   '],
                [3,  '      ╰──────╯      '],
            ],
        ];

        // Amber gradient for each opening stage
        $amberSteps = [
            [100, 80, 30],
            [160, 130, 50],
            [200, 160, 60],
            [self::EYE_R, self::EYE_G, self::EYE_B],
        ];

        foreach ($frames as $fi => $frame) {
            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            [$ar, $ag, $ab] = $amberSteps[$fi];
            $color = Theme::rgb($ar, $ag, $ab);

            foreach ($frame as [$rowOffset, $line]) {
                $drawRow = $this->cy + $rowOffset;
                $lineWidth = mb_strwidth($line);
                $drawCol = max(1, (int) (($this->termWidth - $lineWidth) / 2));

                $chars = mb_str_split($line);
                foreach ($chars as $ci => $char) {
                    $charCol = $drawCol + $ci;
                    if ($drawRow >= 1 && $drawRow <= $this->termHeight && $charCol >= 1 && $charCol <= $this->termWidth) {
                        if ($char === ' ') {
                            $this->prevCells[] = ['row' => $drawRow, 'col' => $charCol];

                            continue;
                        }
                        // Pupil gets special bright treatment
                        if ($char === '◉') {
                            echo Theme::moveTo($drawRow, $charCol)
                                .Theme::rgb(255, 240, 200).$char.$r;
                        } else {
                            echo Theme::moveTo($drawRow, $charCol)
                                .$color.$char.$r;
                        }
                        $this->prevCells[] = ['row' => $drawRow, 'col' => $charCol];
                    }
                }
            }

            usleep(160000);
        }

        usleep(80000);
    }

    /**
     * Phase 2 — Scan (~0.8s).
     *
     * The pupil moves left, then right, then back to center. Faint
     * scan lines emanate horizontally from the eye. Soft white tones
     * for the scanning beam.
     */
    private function phaseScan(): void
    {
        $r = Theme::reset();
        $dimScan = Theme::rgb((int) (self::SCAN_R * 0.3), (int) (self::SCAN_G * 0.3), (int) (self::SCAN_B * 0.3));
        $brightScan = Theme::rgb(self::SCAN_R, self::SCAN_G, self::SCAN_B);
        $amber = Theme::rgb(self::EYE_R, self::EYE_G, self::EYE_B);

        // Pupil movement: center → left → right → center
        $pupilPositions = [];
        $range = min(8, $this->cx - 6);

        // Build smooth scan path
        $scanSteps = 20;
        for ($s = 0; $s < $scanSteps; $s++) {
            $t = $s / $scanSteps;
            if ($t < 0.3) {
                // Move left
                $offset = (int) (-$range * ($t / 0.3));
            } elseif ($t < 0.7) {
                // Move right
                $localT = ($t - 0.3) / 0.4;
                $offset = (int) (-$range + (2 * $range) * $localT);
            } else {
                // Return to center
                $localT = ($t - 0.7) / 0.3;
                $offset = (int) ($range * (1.0 - $localT));
            }
            $pupilPositions[] = $offset;
        }

        foreach ($pupilPositions as $si => $offset) {
            // Erase previous scan elements
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $pupilCol = $this->cx + $offset;

            // Draw the pupil at its current position
            if ($this->cy >= 1 && $this->cy <= $this->termHeight && $pupilCol >= 1 && $pupilCol <= $this->termWidth) {
                echo Theme::moveTo($this->cy, $pupilCol)
                    .Theme::rgb(255, 245, 220).'◉'.$r;
                $this->prevCells[] = ['row' => $this->cy, 'col' => $pupilCol];
            }

            // Draw horizontal scan lines from the pupil outward
            $scanChars = ['›', '─', '·'];
            $leftChars = ['‹', '─', '·'];

            // Right scan beam
            for ($d = 2; $d < min(20, $this->termWidth - $pupilCol); $d += 2) {
                $sCol = $pupilCol + $d;
                if ($sCol >= 1 && $sCol <= $this->termWidth && $this->cy >= 1 && $this->cy <= $this->termHeight) {
                    $fade = max(0.1, 1.0 - ($d / 20.0));
                    $sr = (int) (self::SCAN_R * $fade * 0.5);
                    $sg = (int) (self::SCAN_G * $fade * 0.5);
                    $sb = (int) (self::SCAN_B * $fade * 0.5);
                    $char = $scanChars[min(2, (int) ($d / 7))];
                    echo Theme::moveTo($this->cy, $sCol).Theme::rgb($sr, $sg, $sb).$char.$r;
                    $this->prevCells[] = ['row' => $this->cy, 'col' => $sCol];
                }
            }

            // Left scan beam
            for ($d = 2; $d < min(20, $pupilCol); $d += 2) {
                $sCol = $pupilCol - $d;
                if ($sCol >= 1 && $sCol <= $this->termWidth && $this->cy >= 1 && $this->cy <= $this->termHeight) {
                    $fade = max(0.1, 1.0 - ($d / 20.0));
                    $sr = (int) (self::SCAN_R * $fade * 0.5);
                    $sg = (int) (self::SCAN_G * $fade * 0.5);
                    $sb = (int) (self::SCAN_B * $fade * 0.5);
                    $char = $leftChars[min(2, (int) ($d / 7))];
                    echo Theme::moveTo($this->cy, $sCol).Theme::rgb($sr, $sg, $sb).$char.$r;
                    $this->prevCells[] = ['row' => $this->cy, 'col' => $sCol];
                }
            }

            // Draw faint eye outline (iris ring around pupil)
            $irisChars = [
                [-1, -1, '╭'], [-1, 0, '─'], [-1, 1, '╮'],
                [0, -2, '('],                  [0, 2, ')'],
                [1, -1, '╰'], [1, 0, '─'], [1, 1, '╯'],
            ];
            foreach ($irisChars as [$dy, $dx, $char]) {
                $iRow = $this->cy + $dy;
                $iCol = $pupilCol + $dx;
                if ($iRow >= 1 && $iRow <= $this->termHeight && $iCol >= 1 && $iCol <= $this->termWidth) {
                    echo Theme::moveTo($iRow, $iCol).$amber.$char.$r;
                    $this->prevCells[] = ['row' => $iRow, 'col' => $iCol];
                }
            }

            usleep(40000);
        }

        // Final erase of scan elements
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];
    }

    /**
     * Phase 3 — Pulse (~0.7s).
     *
     * The eye settles at center with the pupil fixed. Green heartbeat
     * pulses radiate outward in concentric rings. "WATCHING" appears
     * below the eye. 2-3 heartbeat cycles.
     */
    private function phasePulse(): void
    {
        $r = Theme::reset();
        $amber = Theme::rgb(self::EYE_R, self::EYE_G, self::EYE_B);

        // Draw settled eye at center
        $eyeLines = [
            [-1, '╭─────╮'],
            [0,  '( ◉ )'],
            [1,  '╰─────╯'],
        ];

        foreach ($eyeLines as [$dy, $line]) {
            $drawRow = $this->cy + $dy;
            $lineWidth = mb_strwidth($line);
            $drawCol = max(1, (int) (($this->termWidth - $lineWidth) / 2));

            $chars = mb_str_split($line);
            foreach ($chars as $ci => $char) {
                $charCol = $drawCol + $ci;
                if ($drawRow >= 1 && $drawRow <= $this->termHeight && $charCol >= 1 && $charCol <= $this->termWidth && $char !== ' ') {
                    if ($char === '◉') {
                        echo Theme::moveTo($drawRow, $charCol)
                            .Theme::rgb(255, 245, 220).$char.$r;
                    } else {
                        echo Theme::moveTo($drawRow, $charCol).$amber.$char.$r;
                    }
                }
            }
        }

        // Heartbeat pulses: 3 beats
        $ringChars = ['·', '○', '◯'];
        $beats = 3;

        for ($beat = 0; $beat < $beats; $beat++) {
            // Each beat: expanding ring from center
            $maxRingRadius = min(10, $this->cx - 4, $this->cy - 4);
            $ringSteps = 6;

            for ($step = 0; $step < $ringSteps; $step++) {
                // Erase previous ring cells
                foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                    if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                        echo Theme::moveTo($pr, $pc).' ';
                    }
                }
                $this->prevCells = [];

                $progress = $step / $ringSteps;
                $radius = $maxRingRadius * $progress;

                // Fade green as ring expands
                $fade = max(0.15, 1.0 - $progress);
                $gr = (int) (self::GREEN_R * $fade);
                $gg = (int) (self::GREEN_G * $fade);
                $gb = (int) (self::GREEN_B * $fade);
                $ringColor = Theme::rgb($gr, $gg, $gb);

                // Draw ring
                if ($radius > 1) {
                    $segments = max(12, (int) ($radius * 6));
                    $ringChar = $ringChars[min(2, $step / 2)];

                    for ($i = 0; $i < $segments; $i++) {
                        $angle = ($i / $segments) * 2 * M_PI;
                        $col = $this->cx + (int) ($radius * cos($angle));
                        $row = $this->cy + (int) ($radius * 0.45 * sin($angle));

                        if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col <= $this->termWidth) {
                            echo Theme::moveTo($row, $col).$ringColor.$ringChar.$r;
                            $this->prevCells[] = ['row' => $row, 'col' => $col];
                        }
                    }
                }

                // Keep eye visible
                if ($this->cy >= 1 && $this->cy <= $this->termHeight && $this->cx >= 1 && $this->cx <= $this->termWidth) {
                    echo Theme::moveTo($this->cy, $this->cx)
                        .Theme::rgb(255, 245, 220).'◉'.$r;
                }

                usleep(25000);
            }

            // Erase final ring of this beat
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            usleep(30000);
        }

        // "WATCHING" label below the eye
        $watchText = 'WATCHING';
        $watchLen = mb_strwidth($watchText);
        $watchCol = max(1, (int) (($this->termWidth - $watchLen) / 2));
        $watchRow = $this->cy + 4;

        if ($watchRow >= 1 && $watchRow <= $this->termHeight) {
            $greenColor = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
            echo Theme::moveTo($watchRow, $watchCol);
            foreach (mb_str_split($watchText) as $char) {
                echo $greenColor.$char.$r;
                usleep(30000);
            }
        }

        usleep(150000);
    }

    /**
     * Phase 4 — Title reveal (~0.8s).
     *
     * "B A B Y S I T" fades in through an amber-to-white gradient.
     * Subtitle "◉ Always watching ◉" types out character by character.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'B A B Y S I T';
        $subtitle = '◉ Always watching ◉';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade through amber → white gradient
        $gradient = [
            [80, 60, 20],
            [120, 95, 35],
            [160, 125, 50],
            [200, 160, 65],
            [self::EYE_R, self::EYE_G, self::EYE_B],
            [255, 220, 140],
            [255, 240, 200],
            [255, 250, 240],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(45000);
        }

        // Subtitle typeout in green
        usleep(100000);
        $green = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $green.$char.$r;
            usleep(22000);
        }

        usleep(400000);
    }
}
