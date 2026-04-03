<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Forensic scanner animation for the :trace debugging command.
 *
 * A scanning beam sweeps the terminal, evidence nodes light up with
 * connections drawn between them, then a magnifying glass focuses
 * on the target and locks the evidence.
 */
class AnsiTrace implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const GRID_CHARS = ['─', '│', '┼', '·'];

    private const NODE_CHARS = ['◉', '◎', '●'];

    private const NODE_LABELS = ['LOG', 'ERR', 'SRC', 'CFG', 'API', 'SQL', 'MEM', 'NET'];

    private const CONNECTION_CHARS = ['─', '│', '╱', '╲', '┼'];

    /** Green phosphor */
    private const GREEN_R = 0;

    private const GREEN_G = 255;

    private const GREEN_B = 65;

    /** Amber highlight */
    private const AMBER_R = 255;

    private const AMBER_G = 200;

    private const AMBER_B = 50;

    /** @var array<int, array{row: int, col: int, label: string}> Evidence node positions */
    private array $nodes = [];

    /**
     * Run the full forensic scanner animation (~3.5s).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseScanBeam();
        $this->phaseEvidenceNodes();
        $this->phaseConnectionWeb();
        $this->phaseFocus();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Scan Beam (~1s).
     *
     * A horizontal green line sweeps top-to-bottom across the screen,
     * leaving faint grid lines behind. The beam itself is bright green
     * phosphor; the residual grid is dark green.
     */
    private function phaseScanBeam(): void
    {
        $r = Theme::reset();
        $totalSteps = $this->termHeight;
        $brightGreen = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);
        $dimGreen = Theme::rgb(0, 60, 15);
        $midGreen = Theme::rgb(0, 120, 30);

        // Grid spacing
        $gridSpacingH = max(4, (int) ($this->termWidth / 12));
        $gridSpacingV = max(3, (int) ($this->termHeight / 8));

        for ($scanRow = 1; $scanRow <= $totalSteps; $scanRow++) {
            // Erase previous frame's beam cells
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Draw the bright scanning beam across the current row
            for ($col = 1; $col < $this->termWidth; $col++) {
                if ($scanRow >= 1 && $scanRow <= $this->termHeight) {
                    echo Theme::moveTo($scanRow, $col).$brightGreen.'─'.$r;
                    $this->prevCells[] = ['row' => $scanRow, 'col' => $col];
                }
            }

            // Leave behind faint grid lines at regular intervals
            // Horizontal grid line
            if ($scanRow % $gridSpacingV === 0) {
                for ($col = 1; $col < $this->termWidth; $col++) {
                    if ($col % $gridSpacingH === 0) {
                        echo Theme::moveTo($scanRow, $col).$dimGreen.'┼'.$r;
                    } else {
                        echo Theme::moveTo($scanRow, $col).$dimGreen.'·'.$r;
                    }
                }
            }

            // Vertical grid lines on all previously scanned rows
            if ($scanRow > 1) {
                for ($col = $gridSpacingH; $col < $this->termWidth; $col += $gridSpacingH) {
                    $row = $scanRow - 1;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        if ($row % $gridSpacingV === 0) {
                            echo Theme::moveTo($row, $col).$dimGreen.'┼'.$r;
                        } else {
                            echo Theme::moveTo($row, $col).$dimGreen.'│'.$r;
                        }
                    }
                }
            }

            // Beam glow: rows immediately above beam get a fading green tint
            for ($glow = 1; $glow <= 2; $glow++) {
                $glowRow = $scanRow - $glow;
                if ($glowRow >= 1 && $glowRow <= $this->termHeight) {
                    $fade = max(0, 180 - $glow * 80);
                    $glowColor = Theme::rgb(0, $fade, (int) ($fade * 0.25));
                    for ($col = 1; $col < $this->termWidth; $col += rand(3, 7)) {
                        echo Theme::moveTo($glowRow, $col).$glowColor.'·'.$r;
                        $this->prevCells[] = ['row' => $glowRow, 'col' => $col];
                    }
                }
            }

            // Frame timing: ~1s / termHeight rows
            usleep((int) (1000000 / $totalSteps));
        }

        // Final erase of beam cells only (grid stays)
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];
    }

    /**
     * Phase 2 — Evidence Nodes (~1s).
     *
     * 8-12 evidence nodes appear at random positions, each a bright dot
     * that pulses once with a short label. Nodes are positioned in a
     * scattered pattern avoiding screen edges.
     */
    private function phaseEvidenceNodes(): void
    {
        $r = Theme::reset();
        $nodeCount = rand(8, 12);
        $labels = self::NODE_LABELS;
        shuffle($labels);

        // Generate node positions avoiding edges and overlap
        $this->nodes = [];
        $margin = 4;
        for ($i = 0; $i < $nodeCount; $i++) {
            $attempts = 0;
            do {
                $row = rand($margin, $this->termHeight - $margin);
                $col = rand($margin + 4, $this->termWidth - $margin - 4);
                $tooClose = false;
                foreach ($this->nodes as $existing) {
                    if (abs($existing['row'] - $row) < 3 && abs($existing['col'] - $col) < 8) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 30);

            $this->nodes[] = [
                'row' => $row,
                'col' => $col,
                'label' => $labels[$i % count($labels)],
            ];
        }

        // Appear one by one with pulse effect
        foreach ($this->nodes as $node) {
            $row = $node['row'];
            $col = $node['col'];
            $label = $node['label'];

            // Pulse: dim -> bright -> settle
            $pulseSteps = [
                [0, 80, 20],
                [0, 160, 40],
                [self::GREEN_R, self::GREEN_G, self::GREEN_B],
                [self::AMBER_R, self::AMBER_G, self::AMBER_B],
                [self::GREEN_R, self::GREEN_G, self::GREEN_B],
                [0, 180, 45],
            ];

            foreach ($pulseSteps as [$pr, $pg, $pb]) {
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    $nodeChar = self::NODE_CHARS[0]; // Use filled circle for initial pulse
                    echo Theme::moveTo($row, $col)
                        .Theme::rgb($pr, $pg, $pb)
                        .$nodeChar
                        .$r;

                    // Draw label to the right
                    $labelCol = $col + 2;
                    if ($labelCol + mb_strlen($label) < $this->termWidth) {
                        echo Theme::moveTo($row, $labelCol)
                            .Theme::rgb($pr, $pg, $pb)
                            .$label
                            .$r;
                    }
                }
                usleep(25000);
            }

            // Settle to steady dim green
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                echo Theme::moveTo($row, $col)
                    .Theme::rgb(0, 180, 45)
                    .self::NODE_CHARS[1] // Hollow circle for settled state
                    .$r;
                $labelCol = $col + 2;
                if ($labelCol + mb_strlen($label) < $this->termWidth) {
                    echo Theme::moveTo($row, $labelCol)
                        .Theme::rgb(0, 140, 35)
                        .$label
                        .$r;
                }
            }

            // Stagger between nodes
            usleep(60000);
        }

        usleep(150000);
    }

    /**
     * Phase 3 — Connection Web (~0.8s).
     *
     * Lines draw between related node pairs, showing cause-effect chains.
     * Connections are drawn in amber, character by character.
     */
    private function phaseConnectionWeb(): void
    {
        $r = Theme::reset();
        $amber = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        $dimAmber = Theme::rgb(160, 125, 30);

        // Select 3-4 pairs of nodes to connect
        $pairCount = min(4, max(3, (int) (count($this->nodes) / 2)));
        $usedIndices = [];
        $pairs = [];

        for ($p = 0; $p < $pairCount; $p++) {
            $attempts = 0;
            do {
                $a = rand(0, count($this->nodes) - 1);
                $b = rand(0, count($this->nodes) - 1);
                $attempts++;
            } while (($a === $b || in_array("$a-$b", $usedIndices) || in_array("$b-$a", $usedIndices)) && $attempts < 20);

            if ($a !== $b) {
                $pairs[] = [$a, $b];
                $usedIndices[] = "$a-$b";
            }
        }

        // Draw each connection line character by character
        foreach ($pairs as [$idxA, $idxB]) {
            $nodeA = $this->nodes[$idxA];
            $nodeB = $this->nodes[$idxB];

            $startRow = $nodeA['row'];
            $startCol = $nodeA['col'];
            $endRow = $nodeB['row'];
            $endCol = $nodeB['col'];

            // Bresenham-style line drawing
            $steps = max(abs($endRow - $startRow), abs($endCol - $startCol));
            if ($steps === 0) {
                continue;
            }

            $drawnCells = [];
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / max(1, $steps);
                $row = (int) round($startRow + ($endRow - $startRow) * $t);
                $col = (int) round($startCol + ($endCol - $startCol) * $t);

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    // Skip if we are on top of a node position
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

                    // Choose connection character based on direction
                    $dRow = $endRow - $startRow;
                    $dCol = $endCol - $startCol;
                    if (abs($dRow) < abs($dCol) / 3) {
                        $char = '─'; // Mostly horizontal
                    } elseif (abs($dCol) < abs($dRow) / 3) {
                        $char = '│'; // Mostly vertical
                    } elseif (($dRow > 0 && $dCol > 0) || ($dRow < 0 && $dCol < 0)) {
                        $char = '╲'; // Diagonal down-right or up-left
                    } else {
                        $char = '╱'; // Diagonal down-left or up-right
                    }

                    // Draw with bright head and dimmer trail
                    echo Theme::moveTo($row, $col).$amber.$char.$r;
                    $drawnCells[] = ['row' => $row, 'col' => $col];

                    // Dim previously drawn cells for trail effect
                    if (count($drawnCells) > 2) {
                        $trail = $drawnCells[count($drawnCells) - 3];
                        echo Theme::moveTo($trail['row'], $trail['col'])
                            .$dimAmber.$char.$r;
                    }
                }

                // Character-by-character drawing speed
                usleep((int) (800000 / max(1, $steps * $pairCount)));
            }

            // Flash the connected nodes amber briefly
            foreach ([$idxA, $idxB] as $idx) {
                $node = $this->nodes[$idx];
                if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $node['col'])
                        .$amber.self::NODE_CHARS[0].$r;
                }
            }
            usleep(40000);

            // Settle nodes back
            foreach ([$idxA, $idxB] as $idx) {
                $node = $this->nodes[$idx];
                if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $node['col'])
                        .Theme::rgb(0, 180, 45).self::NODE_CHARS[1].$r;
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 4 — Focus (~0.7s).
     *
     * All nodes dim except one which pulses bright white. A magnifying
     * glass ASCII art appears around it, then "T R A C E" fades in
     * with "Evidence locked" subtitle.
     */
    private function phaseFocus(): void
    {
        $r = Theme::reset();

        // Pick the target node (one near center for best visual)
        $targetIdx = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($this->nodes as $idx => $node) {
            $dist = abs($node['row'] - $this->cy) + abs($node['col'] - $this->cx);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $targetIdx = $idx;
            }
        }
        $target = $this->nodes[$targetIdx];
        $tRow = $target['row'];
        $tCol = $target['col'];

        // Dim all nodes except target
        $dimColor = Theme::rgb(0, 40, 10);
        foreach ($this->nodes as $idx => $node) {
            if ($idx === $targetIdx) {
                continue;
            }
            if ($node['row'] >= 1 && $node['row'] <= $this->termHeight && $node['col'] >= 1 && $node['col'] < $this->termWidth) {
                echo Theme::moveTo($node['row'], $node['col'])
                    .$dimColor.self::NODE_CHARS[2].$r;
                $labelCol = $node['col'] + 2;
                if ($labelCol + mb_strlen($node['label']) < $this->termWidth) {
                    echo Theme::moveTo($node['row'], $labelCol)
                        .$dimColor.$node['label'].$r;
                }
            }
        }
        usleep(80000);

        // Target node pulses white
        $pulseBright = [
            [0, 200, 50], [0, 255, 65], [180, 255, 180],
            [255, 255, 255], [255, 255, 255], [180, 255, 180],
        ];
        foreach ($pulseBright as [$pr, $pg, $pb]) {
            if ($tRow >= 1 && $tRow <= $this->termHeight && $tCol >= 1 && $tCol < $this->termWidth) {
                echo Theme::moveTo($tRow, $tCol)
                    .Theme::rgb($pr, $pg, $pb)
                    .self::NODE_CHARS[0]
                    .$r;
            }
            usleep(35000);
        }

        // Draw magnifying glass around target
        //   ╭───╮
        //   │   │
        //   ╰───╯
        //      ╲
        $glassColor = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        $glassLines = [
            [-2, -3, '╭───────╮'],
            [-1, -3, '│       │'],
            [0, -3, '│   ◉   │'],
            [1, -3, '│       │'],
            [2, -3, '╰───────╯'],
            [3,  5, '╲'],
            [4,  6, '╲'],
        ];

        foreach ($glassLines as [$dRow, $dCol, $line]) {
            $drawRow = $tRow + $dRow;
            $drawCol = $tCol + $dCol;
            if ($drawRow >= 1 && $drawRow <= $this->termHeight && $drawCol >= 1) {
                // Ensure we don't write past screen edge
                $chars = mb_str_split($line);
                foreach ($chars as $ci => $char) {
                    $charCol = $drawCol + $ci;
                    if ($charCol >= 1 && $charCol < $this->termWidth) {
                        if ($dRow === 0 && $char === '◉') {
                            // The target node itself: bright white
                            echo Theme::moveTo($drawRow, $charCol)
                                .Theme::rgb(255, 255, 255).$char.$r;
                        } else {
                            echo Theme::moveTo($drawRow, $charCol)
                                .$glassColor.$char.$r;
                        }
                    }
                }
            }
            usleep(50000);
        }

        usleep(100000);

        // Clear screen for title
        echo Theme::clearScreen();

        // Fade in "T R A C E"
        $title = 'T R A C E';
        $subtitle = "\u{2295} Evidence locked \u{2295}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        $fadeGradient = [
            [0, 30, 8], [0, 60, 15], [0, 100, 25], [0, 160, 40],
            [0, 220, 55], [self::GREEN_R, self::GREEN_G, self::GREEN_B],
            [100, 255, 120], [200, 255, 210], [255, 255, 255],
        ];

        foreach ($fadeGradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(40000);
        }

        // Subtitle typeout in amber
        usleep(80000);
        $amber = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $amber.$char.$r;
            usleep(20000);
        }

        usleep(400000);
    }
}
