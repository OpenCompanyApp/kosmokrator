<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Neural network formation animation for the :learner command.
 *
 * Golden nodes appear at random positions, electric-blue connections form
 * between nearby pairs, then the network crystallizes into bright white
 * as knowledge solidifies.
 */
class AnsiLearner implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Electric blue connections */
    private const BLUE_R = 60;

    private const BLUE_G = 140;

    private const BLUE_B = 255;

    /** Golden nodes */
    private const GOLD_R = 255;

    private const GOLD_G = 200;

    private const GOLD_B = 80;

    private const CONNECTION_CHARS = ['─', '│', '╱', '╲', '·'];

    /** @var array<int, array{row: int, col: int, pulse: int}> Network node positions */
    private array $nodes = [];

    /**
     * Run the full neural network animation (~3s).
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
            $this->phaseNodes();
            $this->phaseConnections();
            $this->phaseCrystallize();

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
     * Phase 1 — Nodes (~0.8s).
     *
     * 10-15 golden dots appear at scattered positions across the terminal.
     * Each node pulses briefly as it materializes — dim gold fading to
     * bright gold then settling to a steady glow.
     */
    private function phaseNodes(): void
    {
        $r = Theme::reset();
        $nodeCount = mt_rand(10, 15);
        $margin = 4;

        // Generate non-overlapping node positions
        $this->nodes = [];
        for ($i = 0; $i < $nodeCount; $i++) {
            $attempts = 0;
            do {
                $row = mt_rand($margin, $this->termHeight - $margin);
                $col = mt_rand($margin + 2, $this->termWidth - $margin - 2);
                $tooClose = false;
                foreach ($this->nodes as $existing) {
                    if (abs($existing['row'] - $row) < 3 && abs($existing['col'] - $col) < 6) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 40);

            $this->nodes[] = [
                'row' => $row,
                'col' => $col,
                'pulse' => 0,
            ];
        }

        // Appear with pulse effect — staggered materialization
        foreach ($this->nodes as $idx => $node) {
            $row = $node['row'];
            $col = $node['col'];

            // Pulse sequence: dim → bright → settle
            $pulseSteps = [
                [60, 45, 15],
                [120, 90, 30],
                [180, 140, 55],
                [self::GOLD_R, self::GOLD_G, self::GOLD_B],
                [255, 230, 120],  // overshoot bright
                [self::GOLD_R, self::GOLD_G, self::GOLD_B],
                [200, 160, 65],   // settle slightly dimmer
            ];

            foreach ($pulseSteps as [$pr, $pg, $pb]) {
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col)
                        .Theme::rgb($pr, $pg, $pb).'◉'.$r;
                }
                usleep(12000);
            }

            // Settle to steady golden glow
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                echo Theme::moveTo($row, $col)
                    .Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B).'◉'.$r;
            }

            // Stagger between node appearances
            usleep(35000);
        }

        usleep(100000);
    }

    /**
     * Phase 2 — Connections (~1.2s).
     *
     * Lines draw between nearby node pairs. Connections form organically
     * in electric blue, creating a neural-network topology. Each line
     * is drawn character-by-character with a bright head and dimmer trail.
     */
    private function phaseConnections(): void
    {
        $r = Theme::reset();
        $brightBlue = Theme::rgb(self::BLUE_R, self::BLUE_G, self::BLUE_B);
        $dimBlue = Theme::rgb(30, 70, 140);

        // Find all pairs within connection radius
        $connectionRadius = max(15, (int) (min($this->termWidth, $this->termHeight) * 0.4));
        $pairs = [];

        for ($a = 0; $a < count($this->nodes); $a++) {
            for ($b = $a + 1; $b < count($this->nodes); $b++) {
                $dRow = abs($this->nodes[$a]['row'] - $this->nodes[$b]['row']);
                $dCol = abs($this->nodes[$a]['col'] - $this->nodes[$b]['col']);
                $dist = sqrt($dRow * $dRow + $dCol * $dCol);
                if ($dist < $connectionRadius) {
                    $pairs[] = [$a, $b, $dist];
                }
            }
        }

        // Sort by distance (closest first) and limit to avoid visual clutter
        usort($pairs, fn ($x, $y) => $x[2] <=> $y[2]);
        $maxConnections = min(count($pairs), mt_rand(12, 18));
        $pairs = array_slice($pairs, 0, $maxConnections);

        // Draw each connection line character by character
        foreach ($pairs as [$idxA, $idxB, $dist]) {
            $nodeA = $this->nodes[$idxA];
            $nodeB = $this->nodes[$idxB];

            $startRow = $nodeA['row'];
            $startCol = $nodeA['col'];
            $endRow = $nodeB['row'];
            $endCol = $nodeB['col'];

            // Bresenham-style line
            $steps = max(abs($endRow - $startRow), abs($endCol - $startCol));
            if ($steps === 0) {
                continue;
            }

            // Determine line character based on overall direction
            $dRow = $endRow - $startRow;
            $dCol = $endCol - $startCol;
            if (abs($dRow) < abs($dCol) / 3) {
                $lineChar = '─';
            } elseif (abs($dCol) < abs($dRow) / 3) {
                $lineChar = '│';
            } elseif (($dRow > 0 && $dCol > 0) || ($dRow < 0 && $dCol < 0)) {
                $lineChar = '╲';
            } else {
                $lineChar = '╱';
            }

            $drawnCells = [];
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / max(1, $steps);
                $row = (int) round($startRow + ($endRow - $startRow) * $t);
                $col = (int) round($startCol + ($endCol - $startCol) * $t);

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    // Skip if on top of a node
                    $onNode = false;
                    foreach ($this->nodes as $node) {
                        if ($row === $node['row'] && abs($col - $node['col']) <= 1) {
                            $onNode = true;
                            break;
                        }
                    }
                    if ($onNode) {
                        continue;
                    }

                    // Use midpoint dots for sparse connections
                    $drawChar = ($s % 3 === 0 && $steps > 8) ? '·' : $lineChar;

                    // Bright head
                    echo Theme::moveTo($row, $col).$brightBlue.$drawChar.$r;
                    $drawnCells[] = ['row' => $row, 'col' => $col];

                    // Dim trail behind
                    if (count($drawnCells) > 2) {
                        $trail = $drawnCells[count($drawnCells) - 3];
                        echo Theme::moveTo($trail['row'], $trail['col'])
                            .$dimBlue.$drawChar.$r;
                    }
                }

                // Character-by-character speed: total ~1.2s across all connections
                usleep((int) (1200000 / max(1, $steps * $maxConnections)));
            }

            // Brief flash on connected nodes
            foreach ([$idxA, $idxB] as $idx) {
                $node = $this->nodes[$idx];
                if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $node['col'])
                        .Theme::rgb(160, 200, 255).'◉'.$r;
                }
            }
            usleep(20000);

            // Settle nodes back to gold
            foreach ([$idxA, $idxB] as $idx) {
                $node = $this->nodes[$idx];
                if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $node['col'])
                        .Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B).'◉'.$r;
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 3 — Crystallize + Title (~1s).
     *
     * The entire network pulses bright white once as knowledge crystallizes.
     * The central-most node transforms into a star glyph. Screen clears
     * and "L E A R N E R" fades in, followed by a typewriter subtitle.
     */
    private function phaseCrystallize(): void
    {
        $r = Theme::reset();

        // Flash the entire network bright white
        $flashSteps = [
            [200, 220, 255],
            [240, 245, 255],
            [255, 255, 255],
            [240, 245, 255],
            [200, 220, 240],
        ];

        foreach ($flashSteps as [$fr, $fg, $fb]) {
            foreach ($this->nodes as $node) {
                if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $node['col'])
                        .Theme::rgb($fr, $fg, $fb).'◉'.$r;
                }
            }
            usleep(40000);
        }

        // Find the most central node and transform it
        $centralIdx = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($this->nodes as $idx => $node) {
            $dist = abs($node['row'] - $this->cy) + abs($node['col'] - $this->cx);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $centralIdx = $idx;
            }
        }

        $central = $this->nodes[$centralIdx];
        if ($central['row'] >= 1 && $central['row'] <= $this->termHeight && $central['col'] >= 1 && $central['col'] < $this->termWidth) {
            // Transform central node through a brief pulse
            $transformSteps = [
                [255, 255, 255, '◉'],
                [255, 255, 255, '✦'],
                [255, 255, 240, '✦'],
            ];
            foreach ($transformSteps as [$tr, $tg, $tb, $char]) {
                echo Theme::moveTo($central['row'], $central['col'])
                    .Theme::rgb($tr, $tg, $tb).$char.$r;
                usleep(50000);
            }
        }

        usleep(150000);

        // Clear for title
        echo Theme::clearScreen();

        $title = 'L E A R N E R';
        $subtitle = "\u{2726} Pattern extracted \u{2726}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through blue → gold → white gradient
        $gradient = [
            [15, 35, 65],
            [30, 70, 130],
            [self::BLUE_R, self::BLUE_G, self::BLUE_B],
            [100, 170, 255],
            [160, 200, 255],
            [200, 220, 255],
            [230, 240, 255],
            [255, 255, 255],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout in golden
        usleep(120000);
        $gold = Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $gold.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
