<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Ultra QA — testing matrix animation.
 *
 * A grid of test cells lights up in a sweep pattern, flashing red and green.
 * Fix cycles flip red cells to green one by one until the entire matrix
 * converges to all-green, signalling total system health.
 */
class AnsiUltraQa implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** @var array<int, array<int, bool>> Grid of pass/fail states (true = pass) */
    private array $cellStates = [];

    private const COLS = 8;

    private const ROWS = 5;

    private const CELL_WIDTH = 6;

    private const CELL_HEIGHT = 2;

    /** Dim gray for inactive cells. */
    private const DIM_GRAY = [60, 60, 60];

    /** Very dim gray for grid lines. */
    private const GRID_GRAY = [35, 35, 35];

    /** Test red. */
    private const RED = [255, 60, 60];

    /** Test green. */
    private const GREEN = [80, 255, 80];

    /**
     * Run the full Ultra QA animation sequence (grid → sweep → fix → title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseGrid();
        $this->phaseTestSweep();
        $this->phaseFixCycles();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Calculate the top-left corner of the grid so it is centered on screen.
     *
     * @return array{int, int} [startRow, startCol]
     */
    private function gridOrigin(): array
    {
        $gridWidth = self::COLS * self::CELL_WIDTH + 1;
        $gridHeight = self::ROWS * self::CELL_HEIGHT + 1;
        $startCol = max(1, $this->cx - (int) ($gridWidth / 2));
        $startRow = max(1, $this->cy - (int) ($gridHeight / 2));

        return [$startRow, $startCol];
    }

    /**
     * Get the screen position for a cell's content character.
     *
     * @return array{int, int} [row, col]
     */
    private function cellPos(int $gridRow, int $gridCol, int $startRow, int $startCol): array
    {
        $row = $startRow + $gridRow * self::CELL_HEIGHT + 1;
        $col = $startCol + $gridCol * self::CELL_WIDTH + 3;

        return [$row, $col];
    }

    /**
     * Phase 1 — Draw the grid structure and dim cells (~0.5s).
     */
    private function phaseGrid(): void
    {
        $r = Theme::reset();
        [$startRow, $startCol] = $this->gridOrigin();
        $gridColor = Theme::rgb(...self::GRID_GRAY);
        $dimColor = Theme::rgb(...self::DIM_GRAY);

        $gridWidth = self::COLS * self::CELL_WIDTH + 1;
        $gridHeight = self::ROWS * self::CELL_HEIGHT + 1;

        // Draw horizontal grid lines
        for ($gy = 0; $gy <= self::ROWS; $gy++) {
            $row = $startRow + $gy * self::CELL_HEIGHT;
            if ($row < 1 || $row > $this->termHeight) {
                continue;
            }
            for ($x = 0; $x < $gridWidth; $x++) {
                $col = $startCol + $x;
                if ($col < 1 || $col >= $this->termWidth) {
                    continue;
                }
                $isNode = ($x % self::CELL_WIDTH === 0);
                $char = $isNode ? '+' : '─';
                echo Theme::moveTo($row, $col).$gridColor.$char.$r;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
            usleep(18000);
        }

        // Draw vertical grid lines
        for ($gx = 0; $gx <= self::COLS; $gx++) {
            $col = $startCol + $gx * self::CELL_WIDTH;
            if ($col < 1 || $col >= $this->termWidth) {
                continue;
            }
            for ($gy = 0; $gy <= self::ROWS; $gy++) {
                for ($dy = 0; $dy < self::CELL_HEIGHT; $dy++) {
                    $row = $startRow + $gy * self::CELL_HEIGHT + $dy;
                    if ($row < 1 || $row > $this->termHeight) {
                        continue;
                    }
                    if ($dy === 0) {
                        continue; // node already drawn
                    }
                    echo Theme::moveTo($row, $col).$gridColor.'│'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }
        }

        // Place dim cells
        for ($gy = 0; $gy < self::ROWS; $gy++) {
            for ($gx = 0; $gx < self::COLS; $gx++) {
                [$row, $col] = $this->cellPos($gy, $gx, $startRow, $startCol);
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$dimColor.'■'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }
            usleep(25000);
        }

        usleep(100000);
    }

    /**
     * Phase 2 — Test sweep: cells light up left-to-right, top-to-bottom (~1s).
     *
     * Each cell flashes white briefly then settles on green (pass) or red (fail).
     * About 70% red initially. Results stored in $this->cellStates.
     */
    private function phaseTestSweep(): void
    {
        $r = Theme::reset();
        [$startRow, $startCol] = $this->gridOrigin();

        for ($gy = 0; $gy < self::ROWS; $gy++) {
            $this->cellStates[$gy] = [];
            for ($gx = 0; $gx < self::COLS; $gx++) {
                [$row, $col] = $this->cellPos($gy, $gx, $startRow, $startCol);
                if ($row < 1 || $row > $this->termHeight || $col < 1 || $col >= $this->termWidth) {
                    $this->cellStates[$gy][$gx] = true;

                    continue;
                }

                // White flash
                echo Theme::moveTo($row, $col).Theme::rgb(255, 255, 255).'■'.$r;
                usleep(12000);

                // Settle: 30% chance green, 70% red initially
                $pass = rand(1, 100) <= 30;
                $this->cellStates[$gy][$gx] = $pass;

                if ($pass) {
                    echo Theme::moveTo($row, $col).Theme::rgb(...self::GREEN).'✓'.$r;
                } else {
                    echo Theme::moveTo($row, $col).Theme::rgb(...self::RED).'✗'.$r;
                }

                usleep(18000);
            }
        }

        usleep(200000);
    }

    /**
     * Phase 3 — Fix cycles: red cells flip to green with spark effects (~0.8s).
     *
     * Reads cell states from $this->cellStates populated by phaseTestSweep.
     */
    private function phaseFixCycles(): void
    {
        $r = Theme::reset();
        [$startRow, $startCol] = $this->gridOrigin();

        // Collect all failing cells
        $failing = [];
        for ($gy = 0; $gy < self::ROWS; $gy++) {
            for ($gx = 0; $gx < self::COLS; $gx++) {
                if (! ($this->cellStates[$gy][$gx] ?? true)) {
                    $failing[] = [$gy, $gx];
                }
            }
        }

        // Shuffle for random fix order
        shuffle($failing);

        // Fix cells one by one
        foreach ($failing as [$gy, $gx]) {
            [$row, $col] = $this->cellPos($gy, $gx, $startRow, $startCol);
            if ($row < 1 || $row > $this->termHeight || $col < 1 || $col >= $this->termWidth) {
                continue;
            }

            // Brief bright white flash
            echo Theme::moveTo($row, $col).Theme::rgb(255, 255, 255).'■'.$r;

            // Spark effect: tiny bright dots around the cell
            $sparkPositions = [];
            for ($s = 0; $s < 4; $s++) {
                $sr = $row + rand(-1, 1);
                $sc = $col + rand(-2, 2);
                if ($sr >= 1 && $sr <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth && ($sr !== $row || $sc !== $col)) {
                    echo Theme::moveTo($sr, $sc).Theme::rgb(200, 255, 200).'·'.$r;
                    $sparkPositions[] = [$sr, $sc];
                }
            }

            usleep(20000);

            // Settle to green
            echo Theme::moveTo($row, $col).Theme::rgb(...self::GREEN).'✓'.$r;

            // Erase sparks
            foreach ($sparkPositions as [$sr, $sc]) {
                // Restore grid character or blank depending on position
                echo Theme::moveTo($sr, $sc).' ';
            }

            usleep(22000);
        }

        usleep(200000);

        // All-green pulse: flash brighter then settle
        for ($pulse = 0; $pulse < 3; $pulse++) {
            $brightness = $pulse % 2 === 0 ? 255 : 180;
            $green = $pulse % 2 === 0 ? 255 : 220;
            for ($gy = 0; $gy < self::ROWS; $gy++) {
                for ($gx = 0; $gx < self::COLS; $gx++) {
                    [$row, $col] = $this->cellPos($gy, $gx, $startRow, $startCol);
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).Theme::rgb((int) ($brightness * 0.3), $green, (int) ($brightness * 0.3)).'✓'.$r;
                    }
                }
            }
            usleep(80000);
        }

        usleep(100000);
    }

    /**
     * Phase 4 — Title reveal with all-green glow (~0.7s).
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'U L T R A  Q A';
        $subtitle = '✓ All systems nominal ✓';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through green gradient
        $greenGradient = [
            [10, 40, 10], [20, 80, 20], [30, 120, 30], [40, 160, 40],
            [50, 200, 50], [60, 230, 60], [70, 250, 70], [80, 255, 80],
        ];

        foreach ($greenGradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(50000);
        }

        // Final title in bright white
        echo Theme::moveTo($this->cy - 1, $titleCol)
            .Theme::rgb(240, 255, 240).$title.$r;

        // Subtitle typeout
        usleep(120000);
        $green = Theme::rgb(80, 255, 80);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $green.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
