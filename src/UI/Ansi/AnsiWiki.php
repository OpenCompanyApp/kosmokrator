<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Knowledge graph animation for the :wiki command.
 *
 * Stars (knowledge nodes) appear across a dark field, then golden lines
 * connect them forming a constellation network — mirroring the wikilink
 * knowledge graph. A central bright node pulses, then the title fades in.
 */
class AnsiWiki implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int, bright: bool}> Node positions */
    private array $nodes = [];

    /** @var array<int, array{from: int, to: int}> Connections (index pairs into) */
    private array $edges = [];

    /** Warm amber/gold for connections */
    private const AMBER_R = 255;

    private const AMBER_G = 190;

    private const AMBER_B = 60;

    /** Cool blue for nodes */
    private const NODE_R = 120;

    private const NODE_G = 160;

    private const NODE_B = 220;

    /** Bright white for hub nodes */
    private const HUB_R = 255;

    private const HUB_G = 245;

    private const HUB_B = 220;

    private const NODE_CHARS = ['·', '∙', '⋆', '•'];

    private const HUB_CHAR = '✧';

    private const EDGE_CHARS = ['─', '│', '╱', '╲'];

    /**
     * Run the full knowledge graph animation (~2.8s).
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
            $this->generateGraph();
            $this->phaseNodes();
            $this->phaseConnections();
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
     * Pre-generate node positions and edge connections.
     *
     * Creates a graph with 2-3 hub nodes and 15-25 leaf nodes,
     * connected in a realistic knowledge network topology.
     */
    private function generateGraph(): void
    {
        $margin = 3;
        $this->nodes = [];
        $this->edges = [];

        // Place 2-3 hub nodes near the center region
        $hubCount = rand(2, 3);
        for ($i = 0; $i < $hubCount; $i++) {
            $this->nodes[] = [
                'row' => rand(
                    max($margin, $this->cy - (int) ($this->termHeight * 0.25)),
                    min($this->termHeight - $margin, $this->cy + (int) ($this->termHeight * 0.25)),
                ),
                'col' => rand(
                    max($margin, $this->cx - (int) ($this->termWidth * 0.25)),
                    min($this->termWidth - $margin, $this->cx + (int) ($this->termWidth * 0.25)),
                ),
                'bright' => true,
            ];
        }

        // Place 15-25 leaf nodes scattered more widely
        $leafCount = rand(15, 25);
        for ($i = 0; $i < $leafCount; $i++) {
            $attempts = 0;
            do {
                $row = rand($margin, $this->termHeight - $margin);
                $col = rand($margin, $this->termWidth - $margin);
                $tooClose = false;
                foreach ($this->nodes as $existing) {
                    if (abs($existing['row'] - $row) < 2 && abs($existing['col'] - $col) < 4) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 30);

            $this->nodes[] = [
                'row' => $row,
                'col' => $col,
                'bright' => false,
            ];
        }

        // Connect each hub to 4-7 leaves
        foreach ($this->nodes as $idx => $node) {
            if (! $node['bright']) {
                continue;
            }

            // Find nearby leaves sorted by distance
            $candidates = [];
            foreach ($this->nodes as $leafIdx => $leaf) {
                if ($leafIdx === $idx || $leaf['bright']) {
                    continue;
                }
                $dist = abs($leaf['row'] - $node['row']) + abs($leaf['col'] - $node['col']);
                $candidates[] = ['idx' => $leafIdx, 'dist' => $dist];
            }
            usort($candidates, fn ($a, $b) => $a['dist'] <=> $b['dist']);

            // Connect to closest 4-7
            $connectCount = min(count($candidates), rand(4, 7));
            for ($i = 0; $i < $connectCount; $i++) {
                $this->edges[] = ['from' => $idx, 'to' => $candidates[$i]['idx']];
            }
        }

        // Add a few cross-links between hubs
        $hubs = array_filter(array_keys($this->nodes), fn ($i) => $this->nodes[$i]['bright']);
        $hubs = array_values($hubs);
        if (count($hubs) >= 2) {
            $this->edges[] = ['from' => $hubs[0], 'to' => $hubs[1]];
            if (count($hubs) >= 3 && rand(0, 1)) {
                $this->edges[] = ['from' => $hubs[1], 'to' => $hubs[2]];
            }
        }

        // Add a handful of leaf-to-leaf connections for realism
        $leaves = array_values(array_filter(array_keys($this->nodes), fn ($i) => ! $this->nodes[$i]['bright']));
        $crossCount = min(rand(3, 6), (int) (count($leaves) / 2));
        for ($i = 0; $i < $crossCount; $i++) {
            $a = $leaves[rand(0, count($leaves) - 1)];
            $b = $leaves[rand(0, count($leaves) - 1)];
            if ($a !== $b) {
                $this->edges[] = ['from' => $a, 'to' => $b];
            }
        }
    }

    /**
     * Phase 1 — Nodes (~0.6s).
     *
     * Knowledge nodes appear one by one across the field.
     * Hub nodes are brighter and use a star character.
     */
    private function phaseNodes(): void
    {
        $r = Theme::reset();
        $nodeCount = count($this->nodes);
        $delayPerNode = (int) (600_000 / max(1, $nodeCount));

        foreach ($this->nodes as $node) {
            $row = $node['row'];
            $col = $node['col'];

            if ($row < 1 || $row > $this->termHeight || $col < 1 || $col >= $this->termWidth) {
                continue;
            }

            if ($node['bright']) {
                // Hub: bright warm glow
                $color = Theme::rgb(self::HUB_R, self::HUB_G, self::HUB_B);
                echo Theme::moveTo($row, $col).$color.self::HUB_CHAR.$r;

                // Soft glow halo
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
                    $gr = $row + $dr;
                    $gc = $col + $dc;
                    if ($gr >= 1 && $gr <= $this->termHeight && $gc >= 1 && $gc < $this->termWidth) {
                        echo Theme::moveTo($gr, $gc).Theme::rgb(80, 70, 50).'·'.$r;
                    }
                }
            } else {
                // Leaf: cool blue, dim
                $brightness = rand(100, 160);
                $color = Theme::rgb(
                    (int) ($brightness * 0.6),
                    (int) ($brightness * 0.75),
                    min(255, $brightness),
                );
                $char = self::NODE_CHARS[array_rand(self::NODE_CHARS)];
                echo Theme::moveTo($row, $col).$color.$char.$r;
            }

            usleep($delayPerNode);
        }

        usleep(100_000);
    }

    /**
     * Phase 2 — Connections (~1.0s).
     *
     * Golden lines draw between connected nodes, forming the
     * knowledge graph. Lines are drawn point-by-point with a
     * warm amber color.
     */
    private function phaseConnections(): void
    {
        $r = Theme::reset();
        $amber = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        $dimAmber = Theme::rgb(140, 100, 30);

        $edgeCount = count($this->edges);
        $totalBudget = 1_000_000; // 1 second total for all edges

        foreach ($this->edges as $edgeIdx => $edge) {
            $nodeA = $this->nodes[$edge['from']];
            $nodeB = $this->nodes[$edge['to']];

            $startRow = $nodeA['row'];
            $startCol = $nodeA['col'];
            $endRow = $nodeB['row'];
            $endCol = $nodeB['col'];

            $steps = max(abs($endRow - $startRow), abs($endCol - $startCol));
            if ($steps === 0) {
                continue;
            }

            $lineDelay = (int) ($totalBudget / max(1, $edgeCount) / max(1, $steps));

            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / max(1, $steps);
                $drawRow = (int) round($startRow + ($endRow - $startRow) * $t);
                $drawCol = (int) round($startCol + ($endCol - $startCol) * $t);

                if ($drawRow < 1 || $drawRow > $this->termHeight || $drawCol < 1 || $drawCol >= $this->termWidth) {
                    continue;
                }

                // Skip if on top of a node
                $onNode = false;
                foreach ($this->nodes as $node) {
                    if ($drawRow === $node['row'] && $drawCol === $node['col']) {
                        $onNode = true;
                        break;
                    }
                }
                if ($onNode) {
                    continue;
                }

                // Choose edge character
                $dRow = $endRow - $startRow;
                $dCol = $endCol - $startCol;
                if (abs($dRow) < abs($dCol) / 3) {
                    $char = '─';
                } elseif (abs($dCol) < abs($dRow) / 3) {
                    $char = '│';
                } elseif (($dRow > 0 && $dCol > 0) || ($dRow < 0 && $dCol < 0)) {
                    $char = '╲';
                } else {
                    $char = '╱';
                }

                echo Theme::moveTo($drawRow, $drawCol).$dimAmber.$char.$r;

                usleep($lineDelay);
            }

            // Briefly flash the connected nodes
            if ($nodeA['row'] >= 1 && $nodeA['row'] <= $this->termHeight && $nodeA['col'] >= 1 && $nodeA['col'] < $this->termWidth) {
                $cA = $nodeA['bright'] ? Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B) : Theme::rgb(180, 140, 60);
                $chA = $nodeA['bright'] ? self::HUB_CHAR : '•';
                echo Theme::moveTo($nodeA['row'], $nodeA['col']).$cA.$chA.$r;
            }
            if ($nodeB['row'] >= 1 && $nodeB['row'] <= $this->termHeight && $nodeB['col'] >= 1 && $nodeB['col'] < $this->termWidth) {
                $cB = $nodeB['bright'] ? Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B) : Theme::rgb(180, 140, 60);
                $chB = $nodeB['bright'] ? self::HUB_CHAR : '•';
                echo Theme::moveTo($nodeB['row'], $nodeB['col']).$cB.$chB.$r;
            }

            // Settle nodes back
            usleep(15_000);
            $this->redrawNodes();
        }

        usleep(100_000);
    }

    /**
     * Phase 3 — Pulse (~0.4s).
     *
     * The hub nodes pulse brighter once in unison, like knowledge
     * rippling through the graph.
     */
    private function phasePulse(): void
    {
        $r = Theme::reset();

        // Two pulse waves
        for ($wave = 0; $wave < 2; $wave++) {
            // Brighten hubs
            foreach ($this->nodes as $node) {
                if (! $node['bright']) {
                    continue;
                }
                $row = $node['row'];
                $col = $node['col'];
                if ($row < 1 || $row > $this->termHeight || $col < 1 || $col >= $this->termWidth) {
                    continue;
                }

                echo Theme::moveTo($row, $col)
                    .Theme::rgb(255, 255, 255).self::HUB_CHAR.$r;

                // Expand glow
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, 1], [-1, 1], [1, -1]] as [$dr, $dc]) {
                    $gr = $row + $dr;
                    $gc = $col + $dc;
                    if ($gr >= 1 && $gr <= $this->termHeight && $gc >= 1 && $gc < $this->termWidth) {
                        echo Theme::moveTo($gr, $gc)
                            .Theme::rgb(120, 100, 50).'·'.$r;
                    }
                }
            }

            usleep(80_000);

            // Settle back
            $this->redrawNodes();

            usleep(100_000);
        }
    }

    /**
     * Phase 4 — Title (~0.8s).
     *
     * "W I K I" fades in with an amber-to-white gradient.
     * Subtitle types out below.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();

        echo Theme::clearScreen();

        // Scatter faint dots like a knowledge field in the background
        $scatterCount = (int) ($this->termWidth * $this->termHeight * 0.004);
        for ($i = 0; $i < $scatterCount; $i++) {
            $sr = rand(1, $this->termHeight);
            $sc = rand(1, $this->termWidth - 1);
            if ($sr >= 1 && $sr <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth) {
                $b = rand(25, 55);
                echo Theme::moveTo($sr, $sc)
                    .Theme::rgb($b, (int) ($b * 0.85), (int) ($b * 0.5))
                    .'·'.$r;
            }
        }

        // Fade in "W I K I"
        $title = 'W I K I';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $titleRow = $this->cy;

        $fadeGradient = [
            [80, 60, 15],
            [120, 90, 25],
            [160, 120, 35],
            [self::AMBER_R, self::AMBER_G, self::AMBER_B],
            [255, 210, 100],
            [255, 230, 160],
            [255, 245, 210],
            [255, 255, 245],
        ];

        foreach ($fadeGradient as [$rv, $gv, $bv]) {
            if ($titleRow >= 1 && $titleRow <= $this->termHeight) {
                echo Theme::moveTo($titleRow, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(45000);
        }

        // Subtitle typeout
        $subtitle = "\u{2727} Knowledge compounds \u{2727}";
        $subLen = mb_strwidth($subtitle);
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $subRow = $titleRow + 2;

        usleep(100_000);

        $amber = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        if ($subRow >= 1 && $subRow <= $this->termHeight) {
            echo Theme::moveTo($subRow, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo $amber.$char.$r;
                usleep(22000);
            }
        }

        usleep(500_000);
    }

    /**
     * Redraw all nodes at their resting colors.
     */
    private function redrawNodes(): void
    {
        $r = Theme::reset();

        foreach ($this->nodes as $node) {
            $row = $node['row'];
            $col = $node['col'];
            if ($row < 1 || $row > $this->termHeight || $col < 1 || $col >= $this->termWidth) {
                continue;
            }

            if ($node['bright']) {
                echo Theme::moveTo($row, $col)
                    .Theme::rgb(self::HUB_R, self::HUB_G, self::HUB_B).self::HUB_CHAR.$r;
            } else {
                $b = rand(100, 160);
                echo Theme::moveTo($row, $col)
                    .Theme::rgb((int) ($b * 0.6), (int) ($b * 0.75), min(255, $b))
                    .self::NODE_CHARS[array_rand(self::NODE_CHARS)].$r;
            }
        }
    }
}
