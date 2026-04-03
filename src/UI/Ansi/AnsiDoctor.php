<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Medical diagnostic scan animation for the :doctor command.
 *
 * An ECG heartbeat line draws across the screen, diagnostic bars fill
 * with system health readings, then "D O C T O R" fades in with a
 * clean hospital-green phosphor glow.
 */
class AnsiDoctor implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Hospital green */
    private const GREEN_R = 80;

    private const GREEN_G = 255;

    private const GREEN_B = 120;

    /** Amber warning */
    private const AMBER_R = 255;

    private const AMBER_G = 200;

    private const AMBER_B = 50;

    private const DIAG_LABELS = ['SYS', 'PHP', 'CFG', 'NET', 'TUI', 'DEP'];

    /**
     * Run the full diagnostic animation (~3s).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseHeartbeat();
        $this->phaseDiagnostics();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Heartbeat (~1s).
     *
     * An ECG heartbeat line draws across the screen left-to-right. Flat
     * baseline punctuated by sharp QRS-complex spikes. Green phosphor color
     * with a fading trail behind the drawing head.
     */
    private function phaseHeartbeat(): void
    {
        $r = Theme::reset();
        $brightGreen = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
        $dimGreen = Theme::rgb(30, 100, 50);
        $faintGreen = Theme::rgb(15, 50, 25);

        // The heartbeat row sits in the upper third of the screen
        $beatRow = max(3, (int) ($this->termHeight * 0.3));

        // Build the waveform pattern for one beat cycle
        // Offsets from baseline: 0=flat, positive=up, negative=down
        $singleBeat = [
            0, 0, 0, 0, 0, 0,             // flat baseline
            0, 0, -1,                       // small P-wave dip
            0, 0, 0, 0,                    // flat
            -1, 2, 4, 5, 3,                // R-wave spike up
            -2, -4, -3,                     // S-wave dip down
            -1, 0, 0, 0, 0,                // recovery
            0, 1, 1, 0,                    // T-wave bump
            0, 0, 0, 0, 0, 0,             // flat
        ];

        // Repeat the beat pattern to fill the screen width, with 2-3 beats
        $waveform = [];
        $beatsNeeded = 3;
        for ($b = 0; $b < $beatsNeeded; $b++) {
            foreach ($singleBeat as $offset) {
                $waveform[] = $offset;
            }
        }

        // Pad to fill remaining screen width with flat line
        while (count($waveform) < $this->termWidth) {
            $waveform[] = 0;
        }

        // Map characters based on direction of change
        $totalCols = min(count($waveform), $this->termWidth - 2);

        for ($col = 1; $col <= $totalCols; $col++) {
            $offset = $waveform[$col - 1] ?? 0;
            $prevOffset = $waveform[$col - 2] ?? 0;
            $drawRow = $beatRow - $offset;

            // Choose character based on slope
            $delta = $offset - $prevOffset;
            if ($delta > 1) {
                $char = '╱';
            } elseif ($delta < -1) {
                $char = '╲';
            } elseif ($delta === 1) {
                $char = '▏';
            } elseif ($delta === -1) {
                $char = '▎';
            } else {
                $char = '─';
            }

            // Erase previous frame's trail markers
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    // Replace bright trail with dim version
                    echo Theme::moveTo($pr, $pc).$faintGreen.'·'.$r;
                }
            }
            $this->prevCells = [];

            // Draw the bright head
            if ($drawRow >= 1 && $drawRow <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                echo Theme::moveTo($drawRow, $col).$brightGreen.$char.$r;
            }

            // Dim the last few drawn cells as a trailing glow
            for ($trail = 1; $trail <= 3; $trail++) {
                $trailCol = $col - $trail;
                if ($trailCol < 1) {
                    break;
                }
                $trailOffset = $waveform[$trailCol - 1] ?? 0;
                $trailRow = $beatRow - $trailOffset;
                if ($trailRow >= 1 && $trailRow <= $this->termHeight && $trailCol < $this->termWidth) {
                    $fade = max(30, 200 - $trail * 60);
                    echo Theme::moveTo($trailRow, $trailCol)
                        .Theme::rgb((int) ($fade * 0.3), $fade, (int) ($fade * 0.5)).'─'.$r;
                    if ($trail === 3) {
                        $this->prevCells[] = ['row' => $trailRow, 'col' => $trailCol];
                    }
                }
            }

            // Drawing speed: ~1s total across screen
            usleep((int) (1000000 / $totalCols));
        }

        // Final cleanup of trail markers
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).$dimGreen.'─'.$r;
            }
        }
        $this->prevCells = [];

        usleep(100000);
    }

    /**
     * Phase 2 — Diagnostics (~1.2s).
     *
     * Below the heartbeat, diagnostic bars appear one by one, filling
     * left-to-right. Each bar is labeled with a subsystem name and fills
     * to a randomized length with color coding (green=good, amber=warning).
     */
    private function phaseDiagnostics(): void
    {
        $r = Theme::reset();
        $labelColor = Theme::rgb(160, 160, 170);
        $bracketColor = Theme::rgb(80, 80, 90);

        // Bars start below the heartbeat area
        $startRow = max(4, (int) ($this->termHeight * 0.45));
        $barLeft = max(2, (int) ($this->termWidth * 0.15));
        $barMaxWidth = min(50, (int) ($this->termWidth * 0.5));

        // Pre-generate bar results (fill percentage and status)
        $bars = [];
        foreach (self::DIAG_LABELS as $i => $label) {
            // Most systems are healthy (high fill), occasionally one is amber
            $fill = mt_rand(60, 100) / 100.0;
            $isWarning = $fill < 0.75;
            $bars[] = [
                'label' => $label,
                'fill' => $fill,
                'warning' => $isWarning,
                'row' => $startRow + $i * 2,
            ];
        }

        foreach ($bars as $bar) {
            $row = $bar['row'];
            if ($row < 1 || $row > $this->termHeight) {
                continue;
            }

            // Draw label
            $labelCol = max(1, $barLeft - 5);
            if ($labelCol >= 1 && $labelCol < $this->termWidth) {
                echo Theme::moveTo($row, $labelCol).$labelColor.$bar['label'].$r;
            }

            // Draw bracket
            if ($barLeft >= 1 && $barLeft < $this->termWidth) {
                echo Theme::moveTo($row, $barLeft).$bracketColor.'['.$r;
            }
            $endBracketCol = $barLeft + $barMaxWidth + 1;
            if ($endBracketCol >= 1 && $endBracketCol < $this->termWidth) {
                echo Theme::moveTo($row, $endBracketCol).$bracketColor.']'.$r;
            }

            // Fill the bar progressively
            $targetWidth = (int) ($barMaxWidth * $bar['fill']);
            $fillSteps = max(1, (int) ($targetWidth / 4));

            for ($step = 0; $step <= $fillSteps; $step++) {
                $currentWidth = (int) ($targetWidth * ($step / $fillSteps));
                $fillCol = $barLeft + 1;

                // Choose fill character based on density
                for ($x = 0; $x < $currentWidth; $x++) {
                    $charCol = $fillCol + $x;
                    if ($charCol >= 1 && $charCol < $this->termWidth) {
                        // Gradient: dense in front, lighter behind
                        $density = ($x + 1) / max(1, $currentWidth);
                        if ($density > 0.8) {
                            $char = '█';
                        } elseif ($density > 0.6) {
                            $char = '▓';
                        } elseif ($density > 0.3) {
                            $char = '▒';
                        } else {
                            $char = '░';
                        }

                        if ($bar['warning']) {
                            // Amber gradient
                            $cr = (int) (self::AMBER_R * min(1.0, $density + 0.3));
                            $cg = (int) (self::AMBER_G * min(1.0, $density + 0.3));
                            $cb = (int) (self::AMBER_B * min(1.0, $density * 0.5));
                        } else {
                            // Green gradient
                            $cr = (int) (self::GREEN_R * min(1.0, $density + 0.3));
                            $cg = (int) (self::GREEN_G * min(1.0, $density + 0.3));
                            $cb = (int) (self::GREEN_B * min(1.0, $density + 0.3));
                        }
                        echo Theme::moveTo($row, $charCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                    }
                }

                usleep((int) (200000 / max(1, count($bars) * $fillSteps)));
            }

            // Show percentage at end
            $pctText = (int) ($bar['fill'] * 100).'%';
            $pctCol = $endBracketCol + 2;
            if ($pctCol + strlen($pctText) < $this->termWidth) {
                $pctColor = $bar['warning']
                    ? Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B)
                    : Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
                echo Theme::moveTo($row, $pctCol).$pctColor.$pctText.$r;
            }

            usleep(40000);
        }

        usleep(200000);
    }

    /**
     * Phase 3 — Title reveal (~0.8s).
     *
     * Screen clears and "D O C T O R" fades in through a green phosphor
     * gradient, followed by a typewriter subtitle.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'D O C T O R';
        $subtitle = "\u{2295} Diagnostics complete \u{2295}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through green phosphor gradient
        $gradient = [
            [10, 30, 15],
            [20, 60, 30],
            [30, 100, 45],
            [40, 140, 60],
            [50, 180, 80],
            [60, 220, 100],
            [self::GREEN_R, self::GREEN_G, self::GREEN_B],
            [120, 255, 160],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $green = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $green.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
