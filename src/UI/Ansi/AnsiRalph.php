<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Ralph animation — Sisyphean boulder ascent.
 *
 * A boulder is pushed uphill, rolls back, and is pushed again with
 * renewed golden determination. The boulder never stops.
 */
class AnsiRalph implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const SLOPE_CHARS = ['╱', '█', '▓', '▒'];

    private const BOULDER_CHARS = ['●', '◉'];

    private const SPARK_CHARS = ['✦', '∗', '·', '✧'];

    private const GLOW_CHARS = ['✧', '⊛'];

    /** Gray stone. */
    private const STONE_R = 140;

    private const STONE_G = 140;

    private const STONE_B = 150;

    /** Mountain brown. */
    private const BROWN_R = 100;

    private const BROWN_G = 80;

    private const BROWN_B = 60;

    /** Golden determination. */
    private const GOLD_R = 255;

    private const GOLD_G = 200;

    private const GOLD_B = 60;

    /**
     * Run the full animation sequence (mountain → push → rollback+retry → summit+title).
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
            $this->phaseMountain();
            $this->phaseFirstPush();
            $this->phaseRollbackRetry();
            $this->phaseSummitTitle();

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
     * Get the slope coordinates that define the mountain.
     *
     * Returns an array of [row, col] pairs forming the slope from lower-left
     * to upper-right, plus filled terrain beneath the slope line.
     *
     * @return array{slope: list<array{int, int}>, surface: list<array{int, int}>, peak: array{int, int}, base: array{int, int}}
     */
    private function getSlopeGeometry(): array
    {
        // Slope from ~80% height at left to ~30% height at right
        $baseRow = (int) ($this->termHeight * 0.80);
        $peakRow = (int) ($this->termHeight * 0.30);
        $slopeStartCol = (int) ($this->termWidth * 0.15);
        $slopeEndCol = (int) ($this->termWidth * 0.85);

        $slope = [];
        $surface = [];
        $slopeCols = $slopeEndCol - $slopeStartCol;

        for ($i = 0; $i <= $slopeCols; $i++) {
            $col = $slopeStartCol + $i;
            $progress = $i / max(1, $slopeCols);
            $row = (int) ($baseRow - ($baseRow - $peakRow) * $progress);
            $slope[] = [$row, $col];

            // Fill terrain below the slope line
            for ($fillRow = $row + 1; $fillRow <= min($this->termHeight, $baseRow + 2); $fillRow++) {
                $surface[] = [$fillRow, $col];
            }
        }

        return [
            'slope' => $slope,
            'surface' => $surface,
            'peak' => [$peakRow, $slopeEndCol],
            'base' => [$baseRow, $slopeStartCol],
        ];
    }

    /**
     * Get boulder position along the slope at a given progress (0.0 = base, 1.0 = peak).
     *
     * @return array{int, int} [row, col]
     */
    private function getBoulderPosition(float $progress): array
    {
        $geometry = $this->getSlopeGeometry();
        $slopeCount = count($geometry['slope']);
        $index = (int) (max(0, min(1.0, $progress)) * ($slopeCount - 1));

        return $geometry['slope'][$index];
    }

    /**
     * Draw the mountain terrain.
     */
    private function drawMountain(float $revealProgress = 1.0): void
    {
        $r = Theme::reset();
        $geometry = $this->getSlopeGeometry();

        // Draw surface (filled terrain)
        $totalSurface = count($geometry['surface']);
        $revealCount = (int) ($totalSurface * $revealProgress);
        for ($i = 0; $i < $revealCount; $i++) {
            [$row, $col] = $geometry['surface'][$i];
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col <= $this->termWidth) {
                // Deeper is darker brown
                $depth = ($row - $geometry['peak'][0]) / max(1, $geometry['base'][0] - $geometry['peak'][0]);
                $cr = (int) (self::BROWN_R * (0.4 + 0.6 * (1.0 - $depth)));
                $cg = (int) (self::BROWN_G * (0.4 + 0.6 * (1.0 - $depth)));
                $cb = (int) (self::BROWN_B * (0.4 + 0.6 * (1.0 - $depth)));
                $char = $depth > 0.7 ? '░' : ($depth > 0.4 ? '▒' : '▓');
                echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$char.$r;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
        }

        // Draw slope line on top
        $slopeCount = count($geometry['slope']);
        $slopeReveal = (int) ($slopeCount * $revealProgress);
        for ($i = 0; $i < $slopeReveal; $i++) {
            [$row, $col] = $geometry['slope'][$i];
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col <= $this->termWidth) {
                $charIdx = $i % count(self::SLOPE_CHARS);
                echo Theme::moveTo($row, $col)
                    .Theme::rgb(self::STONE_R, self::STONE_G, self::STONE_B)
                    .self::SLOPE_CHARS[$charIdx].$r;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
        }
    }

    /**
     * Draw the boulder at a given position with optional glow.
     */
    private function drawBoulder(int $row, int $col, float $glow = 0.0): void
    {
        $r = Theme::reset();

        // Boulder is ~3 wide x 2 tall
        $boulderShape = [
            [-1, -1, '▓'], [-1, 0, '█'], [-1, 1, '▓'],
            [0, -1, '█'], [0, 0, '◉'], [0, 1, '█'],
        ];

        foreach ($boulderShape as [$dr, $dc, $char]) {
            $bRow = $row + $dr;
            $bCol = $col + $dc;
            if ($bRow >= 1 && $bRow <= $this->termHeight && $bCol >= 1 && $bCol <= $this->termWidth) {
                // Blend stone gray with golden glow
                $cr = (int) (self::STONE_R + (self::GOLD_R - self::STONE_R) * $glow);
                $cg = (int) (self::STONE_G + (self::GOLD_G - self::STONE_G) * $glow);
                $cb = (int) (self::STONE_B + (self::GOLD_B - self::STONE_B) * $glow);
                echo Theme::moveTo($bRow, $bCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                $this->prevCells[] = ['row' => $bRow, 'col' => $bCol];
            }
        }

        // Glow aura around boulder
        if ($glow > 0.3) {
            $auraPositions = [
                [-2, -1], [-2, 0], [-2, 1],
                [-1, -2], [-1, 2],
                [0, -2], [0, 2],
                [1, -1], [1, 0], [1, 1],
            ];
            foreach ($auraPositions as [$dr, $dc]) {
                $aRow = $row + $dr;
                $aCol = $col + $dc;
                if ($aRow >= 1 && $aRow <= $this->termHeight && $aCol >= 1 && $aCol <= $this->termWidth) {
                    $intensity = $glow * (0.4 + mt_rand(0, 60) / 100.0);
                    $cr = (int) (self::GOLD_R * $intensity);
                    $cg = (int) (self::GOLD_G * $intensity);
                    $cb = (int) (self::GOLD_B * $intensity * 0.3);
                    $char = self::GLOW_CHARS[array_rand(self::GLOW_CHARS)];
                    echo Theme::moveTo($aRow, $aCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                    $this->prevCells[] = ['row' => $aRow, 'col' => $aCol];
                }
            }
        }
    }

    /**
     * Phase 1 — Mountain.
     *
     * Draw the slope from lower-left to upper-right using block characters.
     * Brown/gray tones, ~0.6s.
     */
    private function phaseMountain(): void
    {
        $totalSteps = 12;

        for ($step = 0; $step < $totalSteps; $step++) {
            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $progress = ($step + 1) / $totalSteps;
            $this->drawMountain($progress);

            usleep(50000);
        }
    }

    /**
     * Phase 2 — First Push.
     *
     * Boulder moves up the slope from bottom. Trail of effort sparks behind it.
     * ~0.8s.
     */
    private function phaseFirstPush(): void
    {
        $r = Theme::reset();
        $totalSteps = 20;

        for ($step = 0; $step < $totalSteps; $step++) {
            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Redraw mountain as base layer
            $this->drawMountain();

            // Boulder progress: moves up to ~70% of slope then stops
            $progress = ($step / $totalSteps) * 0.70;
            [$bRow, $bCol] = $this->getBoulderPosition($progress);

            // Effort sparks trail behind boulder (below and to the left)
            $sparkCount = min(8, $step);
            for ($s = 0; $s < $sparkCount; $s++) {
                $sparkRow = $bRow + mt_rand(0, 2);
                $sparkCol = $bCol - mt_rand(1, 4);
                if ($sparkRow >= 1 && $sparkRow <= $this->termHeight && $sparkCol >= 1 && $sparkCol <= $this->termWidth) {
                    $brightness = mt_rand(60, 180);
                    $char = self::SPARK_CHARS[array_rand(self::SPARK_CHARS)];
                    echo Theme::moveTo($sparkRow, $sparkCol)
                        .Theme::rgb($brightness, (int) ($brightness * 0.7), (int) ($brightness * 0.3))
                        .$char.$r;
                    $this->prevCells[] = ['row' => $sparkRow, 'col' => $sparkCol];
                }
            }

            $this->drawBoulder($bRow, $bCol);

            usleep(40000);
        }
    }

    /**
     * Phase 3 — Rollback + Retry.
     *
     * Boulder rolls back down (faster), brief pause, then charges back up
     * with golden glow and increased determination. ~0.7s.
     */
    private function phaseRollbackRetry(): void
    {
        $r = Theme::reset();

        // --- Rollback (fast, 8 steps) ---
        $rollSteps = 8;
        $startProgress = 0.70; // where the first push ended

        for ($step = 0; $step < $rollSteps; $step++) {
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $this->drawMountain();

            // Roll back down faster (ease-in, accelerating)
            $t = ($step + 1) / $rollSteps;
            $eased = $t * $t; // quadratic ease-in = accelerating
            $progress = $startProgress * (1.0 - $eased);
            [$bRow, $bCol] = $this->getBoulderPosition($progress);

            $this->drawBoulder($bRow, $bCol);

            usleep(30000);
        }

        // Brief pause at bottom
        usleep(150000);

        // --- Retry with golden determination (10 steps, push to ~85%) ---
        $retrySteps = 10;

        for ($step = 0; $step < $retrySteps; $step++) {
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $this->drawMountain();

            $progress = ($step / max(1, $retrySteps - 1)) * 0.85;
            $glow = min(1.0, ($step / $retrySteps) * 1.2); // intensifying golden glow
            [$bRow, $bCol] = $this->getBoulderPosition($progress);

            // Golden sparks — more intense than phase 2
            $sparkCount = min(12, $step + 3);
            for ($s = 0; $s < $sparkCount; $s++) {
                $sparkRow = $bRow + mt_rand(-1, 2);
                $sparkCol = $bCol - mt_rand(1, 5);
                if ($sparkRow >= 1 && $sparkRow <= $this->termHeight && $sparkCol >= 1 && $sparkCol <= $this->termWidth) {
                    $intensity = 0.5 + $glow * 0.5;
                    $cr = (int) (self::GOLD_R * $intensity);
                    $cg = (int) (self::GOLD_G * $intensity * (0.6 + mt_rand(0, 40) / 100.0));
                    $cb = (int) (self::GOLD_B * $intensity * 0.4);
                    $char = self::SPARK_CHARS[array_rand(self::SPARK_CHARS)];
                    echo Theme::moveTo($sparkRow, $sparkCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                    $this->prevCells[] = ['row' => $sparkRow, 'col' => $sparkCol];
                }
            }

            $this->drawBoulder($bRow, $bCol, $glow);

            usleep(35000);
        }
    }

    /**
     * Phase 4 — Summit + Title.
     *
     * Boulder reaches the peak with a golden explosion of determination
     * particles. Title fades in. ~1.4s.
     */
    private function phaseSummitTitle(): void
    {
        $r = Theme::reset();

        // --- Summit explosion (12 steps) ---
        $peakProgress = 1.0;
        [$peakRow, $peakCol] = $this->getBoulderPosition($peakProgress);
        $explosionSteps = 12;

        $particles = [];
        for ($i = 0; $i < 30; $i++) {
            $angle = ($i / 30) * 2 * M_PI + (mt_rand(-20, 20) / 100.0);
            $speed = mt_rand(80, 200) / 100.0;
            $particles[] = ['angle' => $angle, 'speed' => $speed];
        }

        for ($step = 0; $step < $explosionSteps; $step++) {
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $this->drawMountain();
            $this->drawBoulder($peakRow, $peakCol, 1.0);

            // Explosion particles fly outward
            foreach ($particles as $p) {
                $radius = ($step + 1) * $p['speed'];
                $pCol = $peakCol + (int) ($radius * cos($p['angle']));
                $pRow = $peakRow + (int) ($radius * 0.5 * sin($p['angle']));

                if ($pRow >= 1 && $pRow <= $this->termHeight && $pCol >= 1 && $pCol <= $this->termWidth) {
                    $fade = max(0.2, 1.0 - ($step / $explosionSteps));
                    $cr = (int) (self::GOLD_R * $fade);
                    $cg = (int) (self::GOLD_G * $fade);
                    $cb = (int) (self::GOLD_B * $fade * 0.5);
                    $char = self::GLOW_CHARS[array_rand(self::GLOW_CHARS)];
                    echo Theme::moveTo($pRow, $pCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                    $this->prevCells[] = ['row' => $pRow, 'col' => $pCol];
                }
            }

            usleep(40000);
        }

        // --- Title fade-in ---
        echo Theme::clearScreen();
        $this->drawMountain();
        $this->drawBoulder($peakRow, $peakCol, 1.0);

        $title = 'R A L P H';
        $subtitle = '⊛ The boulder never stops ⊛';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $titleRow = max(1, (int) ($this->termHeight * 0.18));

        // Fade through golden gradient
        $gradient = [
            [40, 30, 10],
            [80, 60, 15],
            [130, 100, 25],
            [180, 140, 35],
            [210, 170, 45],
            [235, 185, 50],
            [250, 195, 55],
            [255, 200, 60],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($titleRow, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $gold = Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B);
        echo Theme::moveTo($titleRow + 2, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $gold.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
