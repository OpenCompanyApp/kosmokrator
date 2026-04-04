<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Deep ocean dive animation for the :deepdive power command.
 *
 * Descends through water layers from bright surface to dark abyss,
 * scans the ocean floor with a spotlight, discovers artifacts and
 * their connections, then reveals the command title.
 */
class AnsiDeepDive implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const BUBBLE_CHARS = ['°', '○', '◦'];

    private const DEPTH_CHARS = ['░', '▒', '▓'];

    private const ARTIFACT_CHARS = ['◈', '✦', '⊛', '◉'];

    private const CONNECTION_CHARS = ['─', '│', '╱', '╲'];

    /** Light blue surface */
    private const SURFACE_R = 100;

    private const SURFACE_G = 180;

    private const SURFACE_B = 255;

    /** Dark navy depths */
    private const DEPTH_R = 10;

    private const DEPTH_G = 20;

    private const DEPTH_B = 60;

    /** Golden artifacts */
    private const GOLD_R = 255;

    private const GOLD_G = 200;

    private const GOLD_B = 80;

    /** White spotlight */
    private const SPOT_R = 240;

    private const SPOT_G = 250;

    private const SPOT_B = 255;

    /** @var array<int, array{row: int, col: int, char: string}> Artifact positions */
    private array $artifacts = [];

    /**
     * Run the full deep dive animation (~3.5s).
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
            $this->phaseDescent();
            $this->phaseScan();
            $this->phaseDiscovery();
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
     * Phase 1 — Descent (~0.8s).
     *
     * Screen fills with a vertical gradient from light blue to dark navy
     * using block characters. Bubble particles float upward from center.
     * A diving probe descends from the top to the center of the screen.
     */
    private function phaseDescent(): void
    {
        $r = Theme::reset();
        $totalSteps = 20;

        // Generate bubble starting positions
        $bubbleCount = 18;
        $bubbles = [];
        for ($i = 0; $i < $bubbleCount; $i++) {
            $bubbles[] = [
                'row' => rand($this->cy, $this->termHeight - 2),
                'col' => $this->cx + rand(-12, 12),
                'speed' => mt_rand(40, 100) / 100.0,
                'char' => self::BUBBLE_CHARS[$i % count(self::BUBBLE_CHARS)],
            ];
        }

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase previous frame cells
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Draw depth gradient background (only on early frames to establish it)
            if ($step < 6) {
                $drawRows = (int) ($this->termHeight * min(1.0, ($step + 1) / 5));
                for ($row = 1; $row <= $drawRows; $row++) {
                    $depthRatio = ($row - 1) / max(1, $this->termHeight - 1);
                    $cr = (int) (self::SURFACE_R + (self::DEPTH_R - self::SURFACE_R) * $depthRatio);
                    $cg = (int) (self::SURFACE_G + (self::DEPTH_G - self::SURFACE_G) * $depthRatio);
                    $cb = (int) (self::SURFACE_B + (self::DEPTH_B - self::SURFACE_B) * $depthRatio);
                    $color = Theme::rgb($cr, $cg, $cb);

                    // Use density characters based on depth
                    $charIdx = min(2, (int) ($depthRatio * 3));
                    $char = self::DEPTH_CHARS[$charIdx];

                    // Sparse fill to avoid slowness
                    for ($col = 1; $col <= $this->termWidth; $col += 3) {
                        if ($col <= $this->termWidth) {
                            echo Theme::moveTo($row, $col).$color.$char.$r;
                        }
                    }
                }
            }

            // Draw bubbles floating upward
            foreach ($bubbles as &$b) {
                $b['row'] -= $b['speed'];
                $b['col'] += (mt_rand(-10, 10) / 10.0); // gentle drift

                $bRow = (int) round($b['row']);
                $bCol = (int) round($b['col']);

                if ($bRow >= 1 && $bRow <= $this->termHeight && $bCol >= 1 && $bCol <= $this->termWidth) {
                    // Bubble color: lighter near top, dimmer in deep water
                    $depthRatio = max(0, min(1.0, ($bRow - 1) / max(1, $this->termHeight - 1)));
                    $br = (int) (180 - 100 * $depthRatio);
                    $bg = (int) (220 - 120 * $depthRatio);
                    $bb = (int) (255 - 100 * $depthRatio);
                    echo Theme::moveTo($bRow, $bCol).Theme::rgb($br, $bg, $bb).$b['char'].$r;
                    $this->prevCells[] = ['row' => $bRow, 'col' => $bCol];
                }

                // Respawn bubbles that float off screen
                if ($b['row'] < 1) {
                    $b['row'] = (float) rand($this->termHeight - 5, $this->termHeight);
                    $b['col'] = (float) ($this->cx + rand(-12, 12));
                }
            }
            unset($b);

            // Draw the diving probe descending
            $probeRow = max(1, min($this->cy, (int) (1 + ($this->cy - 1) * $progress)));
            if ($probeRow >= 1 && $probeRow <= $this->termHeight && $this->cx >= 1 && $this->cx <= $this->termWidth) {
                $probeBright = (int) (150 + 105 * $progress);
                echo Theme::moveTo($probeRow, $this->cx)
                    .Theme::rgb($probeBright, $probeBright, (int) ($probeBright * 1.1)).'▼'.$r;
                $this->prevCells[] = ['row' => $probeRow, 'col' => $this->cx];

                // Draw probe trail
                if ($probeRow > 1) {
                    $trailRow = $probeRow - 1;
                    if ($trailRow >= 1 && $trailRow <= $this->termHeight) {
                        echo Theme::moveTo($trailRow, $this->cx)
                            .Theme::rgb(80, 120, 180).'│'.$r;
                        $this->prevCells[] = ['row' => $trailRow, 'col' => $this->cx];
                    }
                }
            }

            usleep(40000);
        }

        // Final erase of moving elements
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc <= $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];
    }

    /**
     * Phase 2 — Scan (~0.8s).
     *
     * A circular spotlight expands from the center outward, illuminating
     * the dark ocean floor. Dim particles become bright within the light
     * cone, and scattered artifacts are revealed at fixed positions.
     */
    private function phaseScan(): void
    {
        $r = Theme::reset();
        $totalSteps = 20;
        $maxRadius = min($this->cx - 2, $this->cy - 2, 18);

        // Fill screen with dark navy floor
        echo Theme::clearScreen();
        $floorColor = Theme::rgb(self::DEPTH_R, self::DEPTH_G, self::DEPTH_B);
        for ($row = 1; $row <= $this->termHeight; $row++) {
            for ($col = 1; $col <= $this->termWidth; $col += 4) {
                echo Theme::moveTo($row, $col).$floorColor.'·'.$r;
            }
        }

        // Pre-generate artifact positions (scattered around lower half)
        $artifactCount = rand(6, 9);
        $this->artifacts = [];
        $margin = 5;
        for ($i = 0; $i < $artifactCount; $i++) {
            $attempts = 0;
            do {
                $aRow = rand($this->cy - 4, min($this->termHeight - $margin, $this->cy + 6));
                $aCol = rand($margin + 2, $this->termWidth - $margin - 2);
                $tooClose = false;
                foreach ($this->artifacts as $existing) {
                    if (abs($existing['row'] - $aRow) < 3 && abs($existing['col'] - $aCol) < 8) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 30);

            $this->artifacts[] = [
                'row' => $aRow,
                'col' => $aCol,
                'char' => self::ARTIFACT_CHARS[$i % count(self::ARTIFACT_CHARS)],
            ];
        }

        // Expanding spotlight
        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;
            $currentRadius = $maxRadius * $progress;

            // Draw spotlight area
            $spotRows = (int) ceil($currentRadius * 0.6); // Vertical compression
            $spotCols = (int) ceil($currentRadius);

            for ($dy = -$spotRows; $dy <= $spotRows; $dy++) {
                for ($dx = -$spotCols; $dx <= $spotCols; $dx++) {
                    $row = $this->cy + $dy;
                    $col = $this->cx + $dx;

                    if ($row < 1 || $row > $this->termHeight || $col < 1 || $col > $this->termWidth) {
                        continue;
                    }

                    // Elliptical distance check
                    $dist = sqrt(($dx * $dx) + ($dy * $dy * 2.8));
                    if ($dist > $currentRadius) {
                        continue;
                    }

                    // Brightness falls off from center
                    $brightness = max(0.1, 1.0 - ($dist / max(1, $currentRadius)));
                    $sr = (int) (self::SPOT_R * $brightness * 0.4);
                    $sg = (int) (self::SPOT_G * $brightness * 0.4);
                    $sb = (int) (self::SPOT_B * $brightness * 0.4);

                    // Only draw on sparse positions to avoid flooding
                    if (($row + $col) % 3 === 0) {
                        echo Theme::moveTo($row, $col).Theme::rgb($sr, $sg, $sb).'·'.$r;
                    }
                }
            }

            // Reveal artifacts if they fall within the spotlight
            foreach ($this->artifacts as $artifact) {
                $aDx = $artifact['col'] - $this->cx;
                $aDy = $artifact['row'] - $this->cy;
                $aDist = sqrt(($aDx * $aDx) + ($aDy * $aDy * 2.8));

                if ($aDist <= $currentRadius) {
                    $aRow = $artifact['row'];
                    $aCol = $artifact['col'];
                    if ($aRow >= 1 && $aRow <= $this->termHeight && $aCol >= 1 && $aCol <= $this->termWidth) {
                        $revealBright = min(1.0, $progress * 1.5);
                        $ar = (int) (self::GOLD_R * $revealBright * 0.6);
                        $ag = (int) (self::GOLD_G * $revealBright * 0.6);
                        $ab = (int) (self::GOLD_B * $revealBright * 0.6);
                        echo Theme::moveTo($aRow, $aCol)
                            .Theme::rgb($ar, $ag, $ab).$artifact['char'].$r;
                    }
                }
            }

            // Probe glow at center
            if ($this->cx >= 1 && $this->cx <= $this->termWidth && $this->cy >= 1 && $this->cy <= $this->termHeight) {
                echo Theme::moveTo($this->cy, $this->cx)
                    .Theme::rgb(self::SPOT_R, self::SPOT_G, self::SPOT_B).'◆'.$r;
            }

            usleep(40000);
        }

        usleep(100000);
    }

    /**
     * Phase 3 — Discovery (~0.8s).
     *
     * Artifacts pulse bright golden. Connection lines draw between them
     * showing root-cause chains, similar to the trace evidence web.
     * Two visual layers: surface findings (upper) and deep root causes (lower).
     */
    private function phaseDiscovery(): void
    {
        $r = Theme::reset();
        $gold = Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B);
        $dimGold = Theme::rgb(160, 125, 50);
        $brightGold = Theme::rgb(255, 230, 140);

        // Pulse all artifacts golden
        $pulseSteps = [
            [160, 125, 50],
            [200, 160, 60],
            [self::GOLD_R, self::GOLD_G, self::GOLD_B],
            [255, 230, 140],
            [255, 245, 200],
            [255, 230, 140],
            [self::GOLD_R, self::GOLD_G, self::GOLD_B],
        ];

        foreach ($pulseSteps as [$pr, $pg, $pb]) {
            foreach ($this->artifacts as $artifact) {
                $aRow = $artifact['row'];
                $aCol = $artifact['col'];
                if ($aRow >= 1 && $aRow <= $this->termHeight && $aCol >= 1 && $aCol <= $this->termWidth) {
                    echo Theme::moveTo($aRow, $aCol)
                        .Theme::rgb($pr, $pg, $pb).$artifact['char'].$r;
                }
            }
            usleep(30000);
        }

        // Draw connections between artifact pairs
        $pairCount = min(4, max(2, (int) (count($this->artifacts) / 2)));
        $usedIndices = [];
        $pairs = [];

        for ($p = 0; $p < $pairCount; $p++) {
            $attempts = 0;
            do {
                $a = rand(0, count($this->artifacts) - 1);
                $b = rand(0, count($this->artifacts) - 1);
                $attempts++;
            } while (($a === $b || in_array("$a-$b", $usedIndices) || in_array("$b-$a", $usedIndices)) && $attempts < 20);

            if ($a !== $b) {
                $pairs[] = [$a, $b];
                $usedIndices[] = "$a-$b";
            }
        }

        // Draw each connection line character by character
        foreach ($pairs as [$idxA, $idxB]) {
            $nodeA = $this->artifacts[$idxA];
            $nodeB = $this->artifacts[$idxB];

            $startRow = $nodeA['row'];
            $startCol = $nodeA['col'];
            $endRow = $nodeB['row'];
            $endCol = $nodeB['col'];

            $steps = max(abs($endRow - $startRow), abs($endCol - $startCol));
            if ($steps === 0) {
                continue;
            }

            $drawnCells = [];
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / max(1, $steps);
                $row = (int) round($startRow + ($endRow - $startRow) * $t);
                $col = (int) round($startCol + ($endCol - $startCol) * $t);

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col <= $this->termWidth) {
                    // Skip if we are on top of an artifact position
                    $onArtifact = false;
                    foreach ($this->artifacts as $artifact) {
                        if ($row === $artifact['row'] && abs($col - $artifact['col']) <= 1) {
                            $onArtifact = true;
                            break;
                        }
                    }
                    if ($onArtifact) {
                        continue;
                    }

                    // Choose connection character based on direction
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

                    echo Theme::moveTo($row, $col).$gold.$char.$r;
                    $drawnCells[] = ['row' => $row, 'col' => $col, 'char' => $char];

                    // Dim trail behind the bright head
                    if (count($drawnCells) > 2) {
                        $trail = $drawnCells[count($drawnCells) - 3];
                        echo Theme::moveTo($trail['row'], $trail['col'])
                            .$dimGold.$trail['char'].$r;
                    }
                }

                usleep((int) (600000 / max(1, $steps * $pairCount)));
            }

            // Flash connected artifacts bright
            foreach ([$idxA, $idxB] as $idx) {
                $artifact = $this->artifacts[$idx];
                if ($artifact['row'] >= 1 && $artifact['row'] <= $this->termHeight && $artifact['col'] >= 1 && $artifact['col'] <= $this->termWidth) {
                    echo Theme::moveTo($artifact['row'], $artifact['col'])
                        .$brightGold.$artifact['char'].$r;
                }
            }
            usleep(40000);

            // Settle back to steady gold
            foreach ([$idxA, $idxB] as $idx) {
                $artifact = $this->artifacts[$idx];
                if ($artifact['row'] >= 1 && $artifact['row'] <= $this->termHeight && $artifact['col'] >= 1 && $artifact['col'] <= $this->termWidth) {
                    echo Theme::moveTo($artifact['row'], $artifact['col'])
                        .$gold.$artifact['char'].$r;
                }
            }
        }

        usleep(150000);
    }

    /**
     * Phase 4 — Title reveal (~1.1s).
     *
     * "D E E P  D I V E" fades in through a blue-to-white gradient.
     * Subtitle "◈ Root cause illuminated ◈" types out character by character.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'D E E P   D I V E';
        $subtitle = '◈ Root cause illuminated ◈';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade through blue → white gradient
        $gradient = [
            [self::DEPTH_R, self::DEPTH_G, self::DEPTH_B],
            [30, 50, 100],
            [50, 80, 150],
            [70, 120, 200],
            [self::SURFACE_R, self::SURFACE_G, self::SURFACE_B],
            [140, 200, 255],
            [180, 225, 255],
            [210, 240, 255],
            [self::SPOT_R, self::SPOT_G, self::SPOT_B],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(50000);
        }

        // Subtitle typeout in gold
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
