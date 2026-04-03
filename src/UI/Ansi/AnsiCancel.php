<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Implosion / controlled shutdown animation for the :cancel command.
 *
 * Scattered bright particles representing active work collapse inward
 * toward the center, shifting from hot red to calm blue as they
 * converge, then the screen dims to nothing before the title appears.
 */
class AnsiCancel implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Active red */
    private const RED_R = 255;

    private const RED_G = 80;

    private const RED_B = 60;

    /** Calm blue */
    private const BLUE_R = 80;

    private const BLUE_G = 140;

    private const BLUE_B = 255;

    private const ACTIVE_CHARS = ['✦', '◆', '⊛', '∗'];

    private const TRAIL_CHARS = ['·', '∗', '·'];

    /**
     * Run the full implosion animation (~2s).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseActiveState();
        $this->phaseImplosion();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Active State (~0.5s).
     *
     * Scattered bright particles appear across the screen representing
     * active work in progress. Red/orange colors, brief energetic display.
     */
    private function phaseActiveState(): void
    {
        $r = Theme::reset();
        $particleCount = mt_rand(35, 50);

        // Generate scattered particle positions
        $particles = [];
        $margin = 2;
        for ($i = 0; $i < $particleCount; $i++) {
            $row = mt_rand($margin, $this->termHeight - $margin);
            $col = mt_rand($margin, $this->termWidth - $margin - 1);
            $char = self::ACTIVE_CHARS[$i % count(self::ACTIVE_CHARS)];
            $particles[] = [
                'row' => $row,
                'col' => $col,
                'char' => $char,
            ];
        }

        // Flash particles on in 3 quick waves
        $waveSizes = [(int) ($particleCount * 0.4), (int) ($particleCount * 0.35), $particleCount];
        $idx = 0;
        foreach ($waveSizes as $waveEnd) {
            $waveEnd = min($waveEnd, $particleCount);
            for ($i = $idx; $i < $waveEnd; $i++) {
                $p = $particles[$i];
                if ($p['row'] >= 1 && $p['row'] <= $this->termHeight && $p['col'] >= 1 && $p['col'] < $this->termWidth) {
                    // Red-orange range
                    $cr = mt_rand(200, 255);
                    $cg = mt_rand(50, 120);
                    $cb = mt_rand(20, 60);
                    echo Theme::moveTo($p['row'], $p['col'])
                        .Theme::rgb($cr, $cg, $cb).$p['char'].$r;
                }
            }
            $idx = $waveEnd;
            usleep(100000);
        }

        // Brief hold — the scene of active work
        usleep(150000);
    }

    /**
     * Phase 2 — Implosion (~0.8s).
     *
     * All particles accelerate toward center, collapsing inward. Colors
     * shift from red/orange to calm blue as they converge. Each particle
     * leaves a fading trail behind it.
     */
    private function phaseImplosion(): void
    {
        $r = Theme::reset();
        $totalSteps = 20;
        $particleCount = mt_rand(35, 45);

        // Generate particles scattered across screen with trajectories toward center
        $particles = [];
        $margin = 2;
        for ($i = 0; $i < $particleCount; $i++) {
            $startRow = mt_rand($margin, $this->termHeight - $margin);
            $startCol = mt_rand($margin, $this->termWidth - $margin - 1);
            $char = self::ACTIVE_CHARS[$i % count(self::ACTIVE_CHARS)];
            // Speed varies: some converge fast, some slow (acceleration effect)
            $speed = mt_rand(70, 130) / 100.0;
            $particles[] = [
                'startRow' => (float) $startRow,
                'startCol' => (float) $startCol,
                'char' => $char,
                'speed' => $speed,
            ];
        }

        /** @var array<string, array{char: string, age: int, cr: int, cg: int, cb: int}> */
        $trails = [];

        for ($step = 0; $step < $totalSteps; $step++) {
            echo Theme::clearScreen();
            $progress = $step / $totalSteps;

            // Easing: accelerate toward end (quadratic ease-in)
            $eased = $progress * $progress;

            // Color transition: red → blue
            $blendR = (int) (self::RED_R + (self::BLUE_R - self::RED_R) * $progress);
            $blendG = (int) (self::RED_G + (self::BLUE_G - self::RED_G) * $progress);
            $blendB = (int) (self::RED_B + (self::BLUE_B - self::RED_B) * $progress);

            // Draw fading trails
            foreach ($trails as $key => $trail) {
                $trail['age']++;
                $trails[$key] = $trail;
                if ($trail['age'] > 3) {
                    unset($trails[$key]);

                    continue;
                }
                [$tRow, $tCol] = explode(',', $key);
                $tRow = (int) $tRow;
                $tCol = (int) $tCol;
                if ($tCol >= 1 && $tCol < $this->termWidth && $tRow >= 1 && $tRow <= $this->termHeight) {
                    $fade = max(0, 1.0 - $trail['age'] * 0.35);
                    $tr = (int) ($trail['cr'] * $fade * 0.4);
                    $tg = (int) ($trail['cg'] * $fade * 0.4);
                    $tb = (int) ($trail['cb'] * $fade * 0.4);
                    echo Theme::moveTo($tRow, $tCol).Theme::rgb($tr, $tg, $tb).'·'.$r;
                }
            }

            // Draw particles converging toward center
            foreach ($particles as $p) {
                // Lerp from start position to center with eased progress
                $t = min(1.0, $eased * $p['speed']);
                $row = (int) round($p['startRow'] + ($this->cy - $p['startRow']) * $t);
                $col = (int) round($p['startCol'] + ($this->cx - $p['startCol']) * $t);

                if ($col < 1 || $col >= $this->termWidth || $row < 1 || $row > $this->termHeight) {
                    continue;
                }

                // Leave trail
                $trails["{$row},{$col}"] = [
                    'char' => '·',
                    'age' => 0,
                    'cr' => $blendR,
                    'cg' => $blendG,
                    'cb' => $blendB,
                ];

                // Brightness increases as particles converge
                $brightness = 0.6 + 0.4 * $progress;
                $cr = (int) ($blendR * $brightness);
                $cg = (int) ($blendG * $brightness);
                $cb = (int) ($blendB * $brightness);
                echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$p['char'].$r;
            }

            // Central accumulation glow
            if ($progress > 0.3) {
                $intensity = ($progress - 0.3) / 0.7;
                $gr = (int) ($blendR * $intensity);
                $gg = (int) ($blendG * $intensity);
                $gb = (int) (min(255, $blendB * $intensity * 1.2));
                echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($gr, $gg, $gb).'⊛'.$r;
            }

            usleep(40000);
        }

        // Final convergence: center point pulses then dims to nothing
        $dimSteps = [
            [self::BLUE_R, self::BLUE_G, self::BLUE_B, '⊛'],
            [120, 180, 255, '◆'],
            [80, 140, 220, '✦'],
            [40, 80, 140, '∗'],
            [20, 40, 80, '·'],
            [5, 10, 20, '·'],
        ];

        echo Theme::clearScreen();
        foreach ($dimSteps as [$dr, $dg, $db, $char]) {
            echo Theme::moveTo($this->cy, $this->cx)
                .Theme::rgb($dr, $dg, $db).$char.$r;
            usleep(50000);
        }

        // Brief dark pause
        echo Theme::clearScreen();
        usleep(120000);
    }

    /**
     * Phase 3 — Shutdown + Title (~0.7s).
     *
     * After the dark pause, "C A N C E L" fades in calm blue,
     * followed by a typewriter subtitle.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'C A N C E L';
        $subtitle = "\u{25CC} Systems halted \u{25CC}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through blue gradient
        $gradient = [
            [10, 18, 35],
            [20, 35, 70],
            [35, 60, 120],
            [50, 90, 170],
            [65, 115, 215],
            [self::BLUE_R, self::BLUE_G, self::BLUE_B],
            [120, 180, 255],
            [160, 200, 255],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(45000);
        }

        // Subtitle typeout in calm blue
        usleep(80000);
        $blue = Theme::rgb(self::BLUE_R, self::BLUE_G, self::BLUE_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $blue.$char.$r;
            usleep(18000);
        }

        usleep(400000);
    }
}
