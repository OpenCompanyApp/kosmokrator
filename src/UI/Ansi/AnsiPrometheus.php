<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Prometheus Unbound — dramatic eagle animation.
 *
 * In the myth, Zeus sent the Aetos Kaukasios (Caucasian Eagle) to feast on
 * Prometheus's liver each day. When Heracles slays the eagle, the titan is freed.
 * This animation shows the eagle swooping across the screen, struck down by fire,
 * as Prometheus breaks his chains.
 */
class AnsiPrometheus
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int, char: string}> Previous frame cells to erase */
    private array $prevCells = [];

    // Aetos Kaukasios — FRONTAL view, diving toward the viewer
    // Three wing positions for flapping animation
    // Head at top, wings spread to sides, talons at bottom

    private const EAGLE_SPREAD = [
        '                  ▄██▄',
        '                 ▐████▌',
        '                 ▐◉██◉▌',
        '                  ▀██▀▄',
        '                  ▄██▀',
        '      ▄▒░       ▄████▄       ░▒▄',
        '    ▄▓▒░░     ▄▓██████▓▄     ░░▒▓▄',
        '  ▄▓▓▒░░    ▄▓████████▓▓▄    ░░▒▓▓▄',
        ' ▓▓▓▒░░   ▄▓██████████▓▓▓▄   ░░▒▓▓▓',
        '▐▓▓▒▒░  ▄▓████████████▓▓▓▓▄  ░▒▒▓▓▌',
        ' ▀▓▒░  ▐▓██████████████▓▓▓▌  ░▒▓▀',
        '   ▀░   ▀▓████████████▓▓▓▀   ░▀',
        '          ▀▓██████████▓▓▀',
        '            ▀▓██████▓▓▀',
        '              ▀▓██▓▀',
        '              ▐▌  ▐▌',
        '             ▐▌    ▐▌',
    ];

    private const EAGLE_UP = [
        '  ▄▓▓▒░             ░▒▓▓▄',
        ' ▐▓▓▒░░             ░░▒▓▓▌',
        '  ▓▓▒░░    ▄██▄     ░░▒▓▓',
        '  ▀▓▒░    ▐████▌    ░▒▓▀',
        '   ▀░     ▐◉██◉▌    ░▀',
        '           ▀██▀▄',
        '           ▄██▀',
        '         ▄▓████▓▄',
        '       ▄▓████████▓▄',
        '      ▄▓██████████▓▓▄',
        '       ▀▓████████▓▓▀',
        '         ▀▓████▓▓▀',
        '           ▀▓▓▓▀',
        '           ▐▌  ▐▌',
        '          ▐▌    ▐▌',
    ];

    private const EAGLE_DOWN = [
        '                  ▄██▄',
        '                 ▐████▌',
        '                 ▐◉██◉▌',
        '                  ▀██▀▄',
        '                  ▄██▀',
        '         ▄▓████████████▓▄',
        '       ▄▓██████████████▓▓▄',
        '      ▓████████████████▓▓▓▓',
        '       ▀▓██████████████▓▓▀',
        '         ▀▓████████▓▓▀',
        '           ▀▓████▓▀',
        '   ░▒▓▄     ▀▓▓▓▀     ▄▓▒░',
        '  ░░▒▓▓▄   ▐▌  ▐▌   ▄▓▓▒░░',
        '  ░░▒▓▓▓  ▐▌    ▐▌  ▓▓▓▒░░',
        '   ░▒▓▓▀              ▀▓▓▒░',
        '    ░▓▀                ▀▓░',
    ];

    private const FIRE_CHARS = ['░', '▒', '▓', '█', '◆', '✦', '⊛'];

    private const CHAIN_CHARS = ['═', '╪', '╫', '║', '╬', '━', '┃'];

    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor() . Theme::clearScreen();

        register_shutdown_function(fn () => print(Theme::showCursor()));

        $this->phaseEagleFlight();
        $this->phaseChainBreak();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Build per-line colors for a frontal eagle frame.
     * Head at top = golden, body center = brown, wings = gradient dark→light, talons = pale.
     *
     * @return string[] One ANSI color string per line
     */
    private function eagleLineColors(array $frame, float $intensity): array
    {
        $i = $intensity;
        $h = count($frame);

        $eyeGold    = Theme::rgb((int)(255*$i), (int)(240*$i), (int)(100*$i));
        $headGold   = Theme::rgb((int)(255*$i), (int)(220*$i), (int)(140*$i));
        $beak       = Theme::rgb((int)(240*$i), (int)(200*$i), (int)(80*$i));
        $bodyBrown  = Theme::rgb((int)(180*$i), (int)(130*$i), (int)(70*$i));
        $breastTan  = Theme::rgb((int)(200*$i), (int)(160*$i), (int)(90*$i));
        $wingInner  = Theme::rgb((int)(150*$i), (int)(110*$i), (int)(60*$i));
        $wingMid    = Theme::rgb((int)(120*$i), (int)(85*$i),  (int)(45*$i));
        $wingOuter  = Theme::rgb((int)(90*$i),  (int)(65*$i),  (int)(30*$i));
        $wingTip    = Theme::rgb((int)(60*$i),  (int)(42*$i),  (int)(20*$i));
        $talonGray  = Theme::rgb((int)(180*$i), (int)(170*$i), (int)(120*$i));

        // All three frames: head at top, body middle, wings/talons at bottom
        // Build a gradient from top to bottom
        $colors = [];
        for ($line = 0; $line < $h; $line++) {
            $ratio = $h > 1 ? $line / ($h - 1) : 0.5;
            $colors[] = match (true) {
                $ratio < 0.12 => $headGold,     // crown
                $ratio < 0.20 => $eyeGold,      // eyes
                $ratio < 0.30 => $beak,          // beak/neck
                $ratio < 0.42 => $breastTan,     // upper breast
                $ratio < 0.55 => $bodyBrown,     // mid body
                $ratio < 0.65 => $wingInner,     // inner wing
                $ratio < 0.75 => $wingMid,       // mid wing
                $ratio < 0.85 => $wingOuter,     // outer wing/feathers
                $ratio < 0.92 => $talonGray,     // talons
                default => $talonGray,
            };
        }

        return $colors;
    }

    private function phaseEagleFlight(): void
    {
        $r = Theme::reset();
        $frames = [self::EAGLE_SPREAD, self::EAGLE_DOWN, self::EAGLE_SPREAD, self::EAGLE_UP];
        $totalSteps = 30;

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase ALL cells from previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc) . ' ';
                }
            }
            $this->prevCells = [];

            $frame = $frames[$step % 4];
            $frameH = count($frame);

            // Frontal dive: eagle starts high and small, drops toward center
            // Scale effect: we only show the full frame in later steps
            // Early steps: show only the center lines (zooming in effect)
            $visibleRatio = min(1.0, 0.3 + $progress * 0.8);
            $visibleLines = max(3, (int)($frameH * $visibleRatio));
            $skipTop = (int)(($frameH - $visibleLines) / 2);

            // Position: starts above center, descends
            $eagleY = (int)(2 + $progress * ($this->cy - $frameH / 2 - 1));

            // Intensity: fades in from dark
            $intensity = min(1.0, 0.15 + $progress * 0.85);
            $colors = $this->eagleLineColors($frame, $intensity);

            // Draw visible portion of eagle
            for ($i = $skipTop; $i < $skipTop + $visibleLines && $i < $frameH; $i++) {
                $line = $frame[$i];
                $lineWidth = mb_strwidth($line);
                $col = $this->cx - (int)($lineWidth / 2);
                $row = $eagleY + ($i - $skipTop);

                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                $color = $colors[$i] ?? $colors[0];
                $chars = mb_str_split($line);
                foreach ($chars as $ci => $ch) {
                    $cc = $col + $ci;
                    if ($ch === ' ' || $cc < 1 || $cc >= $this->termWidth) {
                        continue;
                    }
                    echo Theme::moveTo($row, $cc) . $color . $ch . $r;
                    $this->prevCells[] = ['row' => $row, 'col' => $cc];
                }
            }

            // Wind/speed lines streaking past the eagle
            if ($step > 6) {
                $lineCount = min(5, 1 + (int)($progress * 5));
                for ($s = 0; $s < $lineCount; $s++) {
                    $sparkRow = $eagleY + $visibleLines + rand(0, 4);
                    $sparkCol = $this->cx + rand(-20, 20);
                    if ($sparkRow >= 1 && $sparkRow <= $this->termHeight && $sparkCol >= 1 && $sparkCol < $this->termWidth) {
                        $heat = rand(100, 255);
                        $green = (int)($heat * (0.4 + rand(0, 20) / 100));
                        // Fire trails below the eagle as it dives
                        echo Theme::moveTo($sparkRow, $sparkCol)
                            . Theme::rgb($heat, $green, 0)
                            . self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                            . $r;
                        $this->prevCells[] = ['row' => $sparkRow, 'col' => $sparkCol];
                    }
                }
            }

            usleep(55000);
        }

        // Final erase
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc) . ' ';
            }
        }
        $this->prevCells = [];
    }

    private function phaseChainBreak(): void
    {
        $r = Theme::reset();

        // Draw chains scattered around center
        $chainPositions = [];
        for ($i = 0; $i < 16; $i++) {
            $row = $this->cy + rand(-5, 5);
            $col = $this->cx + rand(-18, 18);
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                $chainPositions[] = [$row, $col];
                $chainColor = Theme::rgb(100 + rand(0, 40), 80 + rand(0, 30), 60 + rand(0, 20));
                echo Theme::moveTo($row, $col) . $chainColor . self::CHAIN_CHARS[array_rand(self::CHAIN_CHARS)] . $r;
            }
        }
        usleep(250000);

        // Track all fire particles for cleanup
        $firePositions = [];

        // Expanding fire burst shatters chains
        for ($wave = 0; $wave < 10; $wave++) {
            $radius = $wave * 2.5;

            // Fire ring expanding outward
            $particleCount = 12 + $wave * 5;
            for ($p = 0; $p < $particleCount; $p++) {
                $angle = ($p / $particleCount) * 2 * M_PI + ($wave * 0.3);
                $jitter = (rand(0, 100) / 100.0) * 2.0;
                $dist = $radius + $jitter;
                $row = $this->cy + (int)round($dist * sin($angle) * 0.6);
                $col = $this->cx + (int)round($dist * cos($angle));

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    // Color: bright core → orange → dark red at edges
                    $heat = max(40, (int)(255 - $wave * 20 - $jitter * 15));
                    $green = max(0, (int)($heat * 0.55) - $wave * 18);
                    echo Theme::moveTo($row, $col)
                        . Theme::rgb($heat, $green, 0)
                        . self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                        . $r;
                    $firePositions[] = [$row, $col];
                }
            }

            // Erase chain fragments progressively
            foreach ($chainPositions as $key => [$cr, $cc]) {
                if (rand(0, 3) === 0) {
                    echo Theme::moveTo($cr, $cc) . ' ';
                    unset($chainPositions[$key]);
                }
            }

            usleep(55000);
        }

        usleep(200000);

        // Fade fire to embers
        for ($fade = 0; $fade < 3; $fade++) {
            foreach ($firePositions as [$fr, $fc]) {
                if (rand(0, 2) <= $fade) {
                    echo Theme::moveTo($fr, $fc) . ' ';
                }
            }
            usleep(80000);
        }
    }

    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'PROMETHEUS UNBOUND';
        $subtitle = '⚡ All tools auto-approved ⚡';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int)(($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int)(($this->termWidth - $subLen) / 2));

        // Fade in through fire gradient
        $fireGradient = [
            [40, 8, 0], [80, 20, 0], [140, 45, 0], [200, 80, 0],
            [240, 130, 10], [255, 180, 50], [255, 210, 90], [255, 230, 130],
        ];

        foreach ($fireGradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                . Theme::rgb($rv, $g, $b) . $title . $r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $gold = Theme::rgb(255, 200, 80);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $gold . $char . $r;
            usleep(22000);
        }

        usleep(500000);
    }
}
