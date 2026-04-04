<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Observatory animation for the :research command.
 *
 * Stars appear across a night sky, a telescope beam sweeps the heavens
 * illuminating findings, then golden constellation lines connect them
 * before the title fades in.
 */
class AnsiResearch implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const STAR_CHARS = ['.', '·', '✧', '⋆', '∗'];

    private const BEAM_CHARS = ['│', '╱', '╲', '─'];

    private const CONSTELLATION_CHARS = ['─', '│', '╱', '╲'];

    private const CONSTELLATION_LABELS = ['Causa', 'Nexus', 'Fons', 'Verum'];

    /** Deep blue sky */
    private const SKY_R = 20;

    private const SKY_G = 40;

    private const SKY_B = 100;

    /** White star */
    private const STAR_R = 240;

    private const STAR_G = 240;

    private const STAR_B = 255;

    /** Golden connection */
    private const GOLD_R = 255;

    private const GOLD_G = 200;

    private const GOLD_B = 80;

    /** Telescope beam cyan */
    private const CYAN_R = 100;

    private const CYAN_G = 200;

    private const CYAN_B = 255;

    /** @var array<int, array{row: int, col: int, bright: bool, group: int}> Star positions */
    private array $stars = [];

    /** @var array<int, array<int, int>> Constellation groups: group index => array of star indices */
    private array $constellations = [];

    /**
     * Run the full observatory animation (~3s).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseStarfield();
        $this->phaseTelescope();
        $this->phaseConstellations();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Starfield (~0.6s).
     *
     * Stars appear one by one across the screen in white and dim blue
     * tones. About 40-60 stars scattered at random positions, with a
     * few brighter ones that will become "findings" in later phases.
     */
    private function phaseStarfield(): void
    {
        $r = Theme::reset();
        $starCount = rand(40, 60);
        $margin = 2;

        // Pre-generate constellation groups (3-4 groups of 3-4 stars each)
        $groupCount = rand(3, 4);
        $brightCount = $groupCount * rand(3, 4);

        // Generate star positions
        $this->stars = [];
        for ($i = 0; $i < $starCount; $i++) {
            $attempts = 0;
            do {
                $row = rand($margin, $this->termHeight - $margin);
                $col = rand($margin, $this->termWidth - $margin);
                $tooClose = false;
                foreach ($this->stars as $existing) {
                    if (abs($existing['row'] - $row) < 2 && abs($existing['col'] - $col) < 3) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 30);

            // First $brightCount stars are "findings" (will glow brighter later)
            $isBright = ($i < $brightCount);
            $group = $isBright ? ($i % $groupCount) : -1;

            $this->stars[] = [
                'row' => $row,
                'col' => $col,
                'bright' => $isBright,
                'group' => $group,
            ];
        }

        // Build constellation group indices
        $this->constellations = [];
        for ($g = 0; $g < $groupCount; $g++) {
            $this->constellations[$g] = [];
        }
        foreach ($this->stars as $idx => $star) {
            if ($star['bright'] && $star['group'] >= 0) {
                $this->constellations[$star['group']][] = $idx;
            }
        }

        // Appear stars one by one
        $delayPerStar = (int) (600000 / max(1, $starCount));
        foreach ($this->stars as $star) {
            $row = $star['row'];
            $col = $star['col'];

            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                $char = self::STAR_CHARS[array_rand(self::STAR_CHARS)];

                // Dim blue/white tones for regular stars, slightly brighter for findings
                if ($star['bright']) {
                    $brightness = rand(180, 220);
                    $color = Theme::rgb($brightness, $brightness, min(255, $brightness + 20));
                } else {
                    $brightness = rand(60, 130);
                    $blueShift = rand(10, 40);
                    $color = Theme::rgb(
                        max(0, $brightness - $blueShift),
                        max(0, $brightness - (int) ($blueShift * 0.5)),
                        min(255, $brightness + $blueShift)
                    );
                }

                echo Theme::moveTo($row, $col).$color.$char.$r;
            }

            usleep($delayPerStar);
        }

        usleep(100000);
    }

    /**
     * Phase 2 — Telescope (~0.8s).
     *
     * A narrow cyan beam sweeps in an arc across the sky like a lighthouse.
     * As it illuminates stars, the "findings" glow brighter while regular
     * stars stay dim.
     */
    private function phaseTelescope(): void
    {
        $r = Theme::reset();
        $cyan = Theme::rgb(self::CYAN_R, self::CYAN_G, self::CYAN_B);
        $dimCyan = Theme::rgb(40, 80, 120);
        $brightWhite = Theme::rgb(self::STAR_R, self::STAR_G, self::STAR_B);

        // Beam originates from bottom-center and sweeps left to right
        $originRow = $this->termHeight;
        $originCol = $this->cx;

        // Sweep from -60 degrees to +60 degrees (in radians: roughly -1.05 to 1.05)
        $startAngle = -1.05;
        $endAngle = 1.05;
        $angleSteps = 40;
        $stepDelay = (int) (800000 / max(1, $angleSteps));
        $beamLength = (int) ($this->termHeight * 0.85);

        // Track which stars have been illuminated
        $illuminated = [];

        for ($step = 0; $step <= $angleSteps; $step++) {
            // Erase previous beam cells
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Restore previously drawn stars that were erased by beam cleanup
            foreach ($this->stars as $idx => $star) {
                $sRow = $star['row'];
                $sCol = $star['col'];
                if ($sRow >= 1 && $sRow <= $this->termHeight && $sCol >= 1 && $sCol < $this->termWidth) {
                    if (isset($illuminated[$idx])) {
                        $char = $star['bright'] ? '✧' : self::STAR_CHARS[array_rand(self::STAR_CHARS)];
                        if ($star['bright']) {
                            echo Theme::moveTo($sRow, $sCol).$brightWhite.$char.$r;
                        } else {
                            $b = rand(60, 100);
                            echo Theme::moveTo($sRow, $sCol).Theme::rgb($b, $b, $b + 20).$char.$r;
                        }
                    } else {
                        $b = rand(50, 90);
                        $char = self::STAR_CHARS[array_rand(self::STAR_CHARS)];
                        echo Theme::moveTo($sRow, $sCol).Theme::rgb($b - 10, $b, $b + 20).$char.$r;
                    }
                }
            }

            $angle = $startAngle + ($endAngle - $startAngle) * ($step / max(1, $angleSteps));

            // Draw the beam from origin upward at current angle
            for ($dist = 3; $dist < $beamLength; $dist++) {
                $beamRow = $originRow - (int) ($dist * cos($angle));
                $beamCol = $originCol + (int) ($dist * sin($angle));

                if ($beamRow >= 1 && $beamRow <= $this->termHeight && $beamCol >= 1 && $beamCol < $this->termWidth) {
                    // Choose beam character based on angle
                    if (abs($angle) < 0.2) {
                        $beamChar = '│';
                    } elseif ($angle > 0.6) {
                        $beamChar = '╲';
                    } elseif ($angle < -0.6) {
                        $beamChar = '╱';
                    } elseif ($angle > 0) {
                        $beamChar = '╲';
                    } else {
                        $beamChar = '╱';
                    }

                    // Beam fades with distance
                    $fade = max(0.2, 1.0 - ($dist / $beamLength) * 0.7);
                    $cr = (int) (self::CYAN_R * $fade);
                    $cg = (int) (self::CYAN_G * $fade);
                    $cb = (int) (self::CYAN_B * $fade);

                    echo Theme::moveTo($beamRow, $beamCol).Theme::rgb($cr, $cg, $cb).$beamChar.$r;
                    $this->prevCells[] = ['row' => $beamRow, 'col' => $beamCol];
                }
            }

            // Check if beam illuminates any stars
            foreach ($this->stars as $idx => $star) {
                if (isset($illuminated[$idx])) {
                    continue;
                }

                // Calculate angle from origin to star
                $dRow = $originRow - $star['row'];
                $dCol = $star['col'] - $originCol;
                if ($dRow <= 0) {
                    continue;
                }
                $starAngle = atan2($dCol, $dRow);

                // If the beam is close to this star's angle, illuminate it
                if (abs($starAngle - $angle) < 0.08) {
                    $illuminated[$idx] = true;
                    $sRow = $star['row'];
                    $sCol = $star['col'];

                    if ($sRow >= 1 && $sRow <= $this->termHeight && $sCol >= 1 && $sCol < $this->termWidth) {
                        if ($star['bright']) {
                            // Finding stars glow bright white with a flash
                            echo Theme::moveTo($sRow, $sCol)
                                .Theme::rgb(255, 255, 255).'✧'.$r;

                            // Small glow around bright stars
                            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
                                $gr = $sRow + $dr;
                                $gc = $sCol + $dc;
                                if ($gr >= 1 && $gr <= $this->termHeight && $gc >= 1 && $gc < $this->termWidth) {
                                    echo Theme::moveTo($gr, $gc)
                                        .Theme::rgb(60, 80, 120).'·'.$r;
                                    $this->prevCells[] = ['row' => $gr, 'col' => $gc];
                                }
                            }
                        } else {
                            // Regular stars just get a slight brightness bump
                            echo Theme::moveTo($sRow, $sCol)
                                .Theme::rgb(160, 160, 180).'·'.$r;
                        }
                    }
                }
            }

            usleep($stepDelay);
        }

        // Final erase of beam
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        // Restore all stars after beam cleanup
        foreach ($this->stars as $idx => $star) {
            $sRow = $star['row'];
            $sCol = $star['col'];
            if ($sRow >= 1 && $sRow <= $this->termHeight && $sCol >= 1 && $sCol < $this->termWidth) {
                if ($star['bright']) {
                    echo Theme::moveTo($sRow, $sCol)
                        .Theme::rgb(self::STAR_R, self::STAR_G, self::STAR_B).'✧'.$r;
                } else {
                    $b = rand(50, 90);
                    $char = self::STAR_CHARS[array_rand(self::STAR_CHARS)];
                    echo Theme::moveTo($sRow, $sCol).Theme::rgb($b - 10, $b, $b + 20).$char.$r;
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 3 — Constellations (~0.8s).
     *
     * Bright stars (findings) get connected with golden lines forming
     * 3-4 constellation groups. Each group is briefly labeled with a
     * Latin name. Lines are drawn character by character.
     */
    private function phaseConstellations(): void
    {
        $r = Theme::reset();
        $gold = Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B);
        $dimGold = Theme::rgb(160, 125, 50);
        $labelColor = Theme::rgb(200, 170, 80);

        $labels = self::CONSTELLATION_LABELS;

        foreach ($this->constellations as $groupIdx => $starIndices) {
            if (count($starIndices) < 2) {
                continue;
            }

            // Sort stars in the group by proximity to create a reasonable path
            $sorted = $this->sortStarsByProximity($starIndices);

            // Draw lines between consecutive stars in the path
            for ($i = 0; $i < count($sorted) - 1; $i++) {
                $starA = $this->stars[$sorted[$i]];
                $starB = $this->stars[$sorted[$i + 1]];

                $startRow = $starA['row'];
                $startCol = $starA['col'];
                $endRow = $starB['row'];
                $endCol = $starB['col'];

                // Bresenham-style line drawing
                $steps = max(abs($endRow - $startRow), abs($endCol - $startCol));
                if ($steps === 0) {
                    continue;
                }

                $lineDelay = (int) (200000 / max(1, $steps));
                $drawnCells = [];

                for ($s = 0; $s <= $steps; $s++) {
                    $t = $s / max(1, $steps);
                    $drawRow = (int) round($startRow + ($endRow - $startRow) * $t);
                    $drawCol = (int) round($startCol + ($endCol - $startCol) * $t);

                    if ($drawRow >= 1 && $drawRow <= $this->termHeight && $drawCol >= 1 && $drawCol < $this->termWidth) {
                        // Skip cells on top of star positions
                        $onStar = false;
                        foreach ($this->stars as $star) {
                            if ($drawRow === $star['row'] && $drawCol === $star['col']) {
                                $onStar = true;
                                break;
                            }
                        }
                        if ($onStar) {
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

                        echo Theme::moveTo($drawRow, $drawCol).$gold.$char.$r;
                        $drawnCells[] = ['row' => $drawRow, 'col' => $drawCol, 'char' => $char];

                        // Trail: dim previously drawn cells
                        if (count($drawnCells) > 2) {
                            $trail = $drawnCells[count($drawnCells) - 3];
                            echo Theme::moveTo($trail['row'], $trail['col'])
                                .$dimGold.$trail['char'].$r;
                        }
                    }

                    usleep($lineDelay);
                }

                // Settle all drawn cells to dim gold
                foreach ($drawnCells as $cell) {
                    echo Theme::moveTo($cell['row'], $cell['col'])
                        .$dimGold.$cell['char'].$r;
                }
            }

            // Flash the constellation's stars gold briefly
            foreach ($sorted as $sIdx) {
                $star = $this->stars[$sIdx];
                if ($star['row'] >= 1 && $star['row'] <= $this->termHeight && $star['col'] >= 1 && $star['col'] < $this->termWidth) {
                    echo Theme::moveTo($star['row'], $star['col'])
                        .$gold.'✧'.$r;
                }
            }

            // Label the constellation near its centroid
            $label = $labels[$groupIdx % count($labels)];
            $centroidRow = 0;
            $centroidCol = 0;
            foreach ($sorted as $sIdx) {
                $centroidRow += $this->stars[$sIdx]['row'];
                $centroidCol += $this->stars[$sIdx]['col'];
            }
            $centroidRow = (int) ($centroidRow / max(1, count($sorted)));
            $centroidCol = (int) ($centroidCol / max(1, count($sorted)));

            // Place label slightly below the centroid
            $labelRow = min($this->termHeight, $centroidRow + 2);
            $labelCol = max(1, $centroidCol - (int) (mb_strwidth($label) / 2));
            if ($labelRow >= 1 && $labelRow <= $this->termHeight && $labelCol >= 1 && $labelCol + mb_strwidth($label) < $this->termWidth) {
                echo Theme::moveTo($labelRow, $labelCol).$labelColor.$label.$r;
            }

            usleep(60000);

            // Settle stars back to bright white
            foreach ($sorted as $sIdx) {
                $star = $this->stars[$sIdx];
                if ($star['row'] >= 1 && $star['row'] <= $this->termHeight && $star['col'] >= 1 && $star['col'] < $this->termWidth) {
                    echo Theme::moveTo($star['row'], $star['col'])
                        .Theme::rgb(self::STAR_R, self::STAR_G, self::STAR_B).'✧'.$r;
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 4 — Title (~0.8s).
     *
     * "R E S E A R C H" fades in with a golden-to-white gradient.
     * Subtitle "Knowledge mapped" typewriter-appears beneath.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();

        // Clear screen for title
        echo Theme::clearScreen();

        // Fade in "R E S E A R C H"
        $title = 'R E S E A R C H';
        $subtitle = "\u{2727} Knowledge mapped \u{2727}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        $fadeGradient = [
            [60, 50, 15], [100, 80, 25], [140, 110, 35], [180, 140, 50],
            [self::GOLD_R, self::GOLD_G, self::GOLD_B],
            [255, 215, 120], [255, 230, 170], [255, 245, 220], [255, 255, 255],
        ];

        foreach ($fadeGradient as [$rv, $gv, $bv]) {
            if ($this->cy - 1 >= 1 && $this->cy - 1 <= $this->termHeight) {
                echo Theme::moveTo($this->cy - 1, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(40000);
        }

        // Subtitle typeout in gold
        usleep(80000);
        $gold = Theme::rgb(self::GOLD_R, self::GOLD_G, self::GOLD_B);
        if ($this->cy + 1 >= 1 && $this->cy + 1 <= $this->termHeight) {
            echo Theme::moveTo($this->cy + 1, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo $gold.$char.$r;
                usleep(20000);
            }
        }

        usleep(400000);
    }

    /**
     * Sort star indices by proximity to form a reasonable path.
     *
     * Uses a nearest-neighbor greedy approach starting from the first star.
     *
     * @param  array<int, int>  $indices  Star indices in the group
     * @return array<int, int> Sorted star indices
     */
    private function sortStarsByProximity(array $indices): array
    {
        if (count($indices) <= 2) {
            return $indices;
        }

        $sorted = [array_shift($indices)];
        while (count($indices) > 0) {
            $lastStar = $this->stars[$sorted[count($sorted) - 1]];
            $bestDist = PHP_INT_MAX;
            $bestKey = 0;

            foreach ($indices as $key => $idx) {
                $star = $this->stars[$idx];
                $dist = abs($star['row'] - $lastStar['row']) + abs($star['col'] - $lastStar['col']);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestKey = $key;
                }
            }

            $sorted[] = $indices[$bestKey];
            unset($indices[$bestKey]);
            $indices = array_values($indices);
        }

        return $sorted;
    }
}
