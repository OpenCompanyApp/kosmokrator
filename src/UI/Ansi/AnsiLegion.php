<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Legion animation — five perspective agents summoned, deliberate, and converge.
 *
 * Four phases: five figures materialize across a horizontal axis, thought
 * particles clash at center, converge into the Moirai weaving knot, and
 * the title reveals. ~3.5 seconds total.
 */
class AnsiLegion implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const ATHENA_COLOR = [220, 180, 50];

    private const HERMES_COLOR = [80, 200, 220];

    private const APOLLO_COLOR = [160, 100, 240];

    private const ARGUS_COLOR = [220, 80, 80];

    private const OCCAM_COLOR = [220, 220, 220];

    private const FIGURES = [
        ['glyph' => '♃', 'label' => 'Athena', 'color' => self::ATHENA_COLOR],
        ['glyph' => '☿', 'label' => 'Hermes', 'color' => self::HERMES_COLOR],
        ['glyph' => '☉', 'label' => 'Apollo', 'color' => self::APOLLO_COLOR],
        ['glyph' => '♂', 'label' => 'Argus', 'color' => self::ARGUS_COLOR],
        ['glyph' => '○', 'label' => 'Occam', 'color' => self::OCCAM_COLOR],
    ];

    private const DEBATE_CHARS = ['·', '∗', '✦', '•', '◦'];

    private const CONVERGENCE_CHARS = ['✦', '∗', '·', '◦'];

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
            $this->phaseSummoning();
            $this->phaseDeliberation();
            $this->phaseConvergence();
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
     * Calculate the column positions for the 5 figures, evenly spaced.
     *
     * @return int[]
     */
    private function figureColumns(): array
    {
        $spacing = (int) ($this->termWidth / 6);
        $cols = [];
        for ($i = 0; $i < 5; $i++) {
            $cols[] = $spacing * ($i + 1);
        }

        return $cols;
    }

    /**
     * Phase 1 — Summoning (~1.2s).
     *
     * Five perspective symbols materialize one by one across a horizontal
     * axis. Each fades in through a 4-step brightness ramp, then its label
     * appears below. A connecting thread draws between all five.
     */
    private function phaseSummoning(): void
    {
        $r = Theme::reset();
        $cols = $this->figureColumns();
        $row = $this->cy;

        // Fade in each figure sequentially
        foreach (self::FIGURES as $i => $figure) {
            $col = $cols[$i];
            $glowSteps = [0.3, 0.5, 0.7, 1.0];

            foreach ($glowSteps as $mul) {
                $fR = (int) ($figure['color'][0] * $mul);
                $fG = (int) ($figure['color'][1] * $mul);
                $fB = (int) ($figure['color'][2] * $mul);

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).Theme::rgb($fR, $fG, $fB).$figure['glyph'].$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }

                usleep(30000);
            }

            // Draw label below
            $labelRow = $row + 1;
            $labelLen = mb_strwidth($figure['label']);
            $labelCol = max(1, $col - (int) ($labelLen / 2));

            if ($labelRow >= 1 && $labelRow <= $this->termHeight) {
                $dimR = (int) ($figure['color'][0] * 0.5);
                $dimG = (int) ($figure['color'][1] * 0.5);
                $dimB = (int) ($figure['color'][2] * 0.5);
                $labelColor = Theme::rgb($dimR, $dimG, $dimB);

                for ($c = 0; $c < $labelLen; $c++) {
                    $lCol = $labelCol + $c;
                    if ($lCol >= 1 && $lCol < $this->termWidth) {
                        echo Theme::moveTo($labelRow, $lCol)
                            .$labelColor.mb_substr($figure['label'], $c, 1).$r;
                        $this->prevCells[] = ['row' => $labelRow, 'col' => $lCol];
                    }
                }
            }

            usleep(80000);
        }

        // Draw connecting thread between all five figures
        $lineRow = $row - 1;
        if ($lineRow >= 1 && $lineRow <= $this->termHeight) {
            $lineColor = Theme::rgb(60, 60, 80);
            $startCol = max(1, $cols[0] - 1);
            $endCol = min($this->termWidth - 1, $cols[4] + 1);

            for ($c = $startCol; $c <= $endCol; $c++) {
                echo Theme::moveTo($lineRow, $c).$lineColor.'─'.$r;
                $this->prevCells[] = ['row' => $lineRow, 'col' => $c];
            }
        }

        usleep(150000);
    }

    /**
     * Phase 2 — Deliberation (~0.8s).
     *
     * Thought particles emit from each of the five figures toward center.
     * Colors mix at collision points with white flashes of disagreement.
     */
    private function phaseDeliberation(): void
    {
        $r = Theme::reset();
        $cols = $this->figureColumns();
        $figRow = $this->cy;

        $sources = [];
        foreach (self::FIGURES as $i => $figure) {
            $sources[] = [
                'row' => $figRow,
                'col' => $cols[$i],
                'color' => $figure['color'],
            ];
        }

        $totalSteps = 20;
        $debateCells = [];

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase previous debate particles
            foreach ($debateCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $debateCells = [];

            // Emit particles from each source toward center
            foreach ($sources as $source) {
                $particleCount = (int) (2 + $progress * 3);

                for ($p = 0; $p < $particleCount; $p++) {
                    $t = rand(10, 90) / 100.0;
                    $pRow = (int) ($source['row'] + $t * ($this->cy - $source['row']) + rand(-2, 2));
                    $pCol = (int) ($source['col'] + $t * ($this->cx - $source['col']) + rand(-3, 3));

                    if ($pRow < 1 || $pRow > $this->termHeight || $pCol < 1 || $pCol >= $this->termWidth) {
                        continue;
                    }

                    // Color fades toward white at center
                    $centerDist = sqrt(($pRow - $this->cy) ** 2 + (($pCol - $this->cx) * 0.5) ** 2);
                    $colorBlend = min(1.0, $centerDist / 15.0);

                    $pR = (int) ($source['color'][0] * $colorBlend + 255 * (1.0 - $colorBlend));
                    $pG = (int) ($source['color'][1] * $colorBlend + 255 * (1.0 - $colorBlend));
                    $pB = (int) ($source['color'][2] * $colorBlend + 255 * (1.0 - $colorBlend));

                    // Collision flash near center
                    if ($centerDist < 3 && rand(0, 2) === 0) {
                        $pR = min(255, $pR + 60);
                        $pG = min(255, $pG + 60);
                        $pB = min(255, $pB + 60);
                    }

                    $char = self::DEBATE_CHARS[array_rand(self::DEBATE_CHARS)];
                    echo Theme::moveTo($pRow, $pCol)
                        .Theme::rgb(min(255, $pR), min(255, $pG), min(255, $pB))
                        .$char.$r;
                    $debateCells[] = ['row' => $pRow, 'col' => $pCol];
                }
            }

            // Occasional conflict flash at center
            if ($step % 4 === 0 && $step > 4) {
                $flashChars = ['✦', '∗', '✧'];
                for ($f = 0; $f < 3; $f++) {
                    $fRow = $this->cy + rand(-1, 1);
                    $fCol = $this->cx + rand(-2, 2);
                    if ($fRow >= 1 && $fRow <= $this->termHeight && $fCol >= 1 && $fCol < $this->termWidth) {
                        echo Theme::moveTo($fRow, $fCol)
                            .Theme::rgb(255, 255, 255)
                            .$flashChars[array_rand($flashChars)].$r;
                        $debateCells[] = ['row' => $fRow, 'col' => $fCol];
                    }
                }
            }

            usleep(40000);
        }

        // Final cleanup of debate particles
        foreach ($debateCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
    }

    /**
     * Phase 3 — Convergence (~0.8s).
     *
     * Particles slow and spiral inward. All five colors blend to white.
     * The figures dim as the Moirai weaving knot (⟡) brightens at center.
     * A radiating ring expands outward.
     */
    private function phaseConvergence(): void
    {
        $r = Theme::reset();
        $cols = $this->figureColumns();
        $allColors = [
            self::ATHENA_COLOR, self::HERMES_COLOR, self::APOLLO_COLOR,
            self::ARGUS_COLOR, self::OCCAM_COLOR,
        ];

        $totalSteps = 20;
        $convergeCells = [];

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase previous convergence particles
            foreach ($convergeCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $convergeCells = [];

            // Contracting particle cloud
            $cloudRadius = max(1.0, 10.0 * (1.0 - $progress));
            $particleCount = max(3, (int) (15 * (1.0 - $progress * 0.5)));

            for ($p = 0; $p < $particleCount; $p++) {
                $rad = deg2rad(rand(0, 359));
                $dist = rand(10, (int) ($cloudRadius * 100)) / 100.0;
                $pRow = (int) ($this->cy + sin($rad) * $dist);
                $pCol = (int) ($this->cx + cos($rad) * $dist * 2);

                if ($pRow < 1 || $pRow > $this->termHeight || $pCol < 1 || $pCol >= $this->termWidth) {
                    continue;
                }

                // Color converges to white
                $baseColor = $allColors[array_rand($allColors)];
                $pR = (int) ($baseColor[0] * (1.0 - $progress) + 255 * $progress);
                $pG = (int) ($baseColor[1] * (1.0 - $progress) + 255 * $progress);
                $pB = (int) ($baseColor[2] * (1.0 - $progress) + 255 * $progress);

                $char = self::CONVERGENCE_CHARS[array_rand(self::CONVERGENCE_CHARS)];
                echo Theme::moveTo($pRow, $pCol)
                    .Theme::rgb(min(255, $pR), min(255, $pG), min(255, $pB))
                    .$char.$r;
                $convergeCells[] = ['row' => $pRow, 'col' => $pCol];
            }

            // Dim the five figures progressively
            $figDim = max(0.15, 1.0 - $progress * 0.85);
            foreach (self::FIGURES as $i => $figure) {
                $fRow = $this->cy;
                $fCol = $cols[$i];
                if ($fRow >= 1 && $fRow <= $this->termHeight && $fCol >= 1 && $fCol < $this->termWidth) {
                    $fR = (int) ($figure['color'][0] * $figDim);
                    $fG = (int) ($figure['color'][1] * $figDim);
                    $fB = (int) ($figure['color'][2] * $figDim);
                    echo Theme::moveTo($fRow, $fCol)
                        .Theme::rgb($fR, $fG, $fB).$figure['glyph'].$r;
                }
            }

            // Brightening Moirai knot at center
            if ($progress > 0.3) {
                $intensity = ($progress - 0.3) / 0.7;
                $kR = (int) (60 + 160 * $intensity);
                $kG = (int) (50 + 130 * $intensity);
                $kB = (int) (15 + 35 * $intensity);
                if ($this->cy >= 1 && $this->cy <= $this->termHeight
                    && $this->cx >= 1 && $this->cx < $this->termWidth) {
                    echo Theme::moveTo($this->cy, $this->cx)
                        .Theme::rgb($kR, $kG, $kB).'⟡'.$r;
                    $convergeCells[] = ['row' => $this->cy, 'col' => $this->cx];
                }
            }

            usleep(40000);
        }

        // Erase convergence particles
        foreach ($convergeCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }

        // Final bright Moirai knot
        if ($this->cy >= 1 && $this->cy <= $this->termHeight
            && $this->cx >= 1 && $this->cx < $this->termWidth) {
            echo Theme::moveTo($this->cy, $this->cx)
                .Theme::rgb(220, 180, 50).'⟡'.$r;
        }

        usleep(80000);

        // Radiating ring from center
        for ($ring = 1; $ring <= 8; $ring++) {
            $ringCells = [];
            $ringBright = max(40, 220 - $ring * 25);
            $goldBlend = max(0.0, 1.0 - $ring / 8.0);

            for ($angle = 0; $angle < 360; $angle += 12) {
                $rad = deg2rad($angle);
                $rRow = (int) ($this->cy + sin($rad) * $ring * 0.6);
                $rCol = (int) ($this->cx + cos($rad) * $ring * 1.2);

                if ($rRow >= 1 && $rRow <= $this->termHeight && $rCol >= 1 && $rCol < $this->termWidth) {
                    $cR = (int) ($ringBright * (0.5 + 0.5 * $goldBlend));
                    $cG = (int) ($ringBright * (0.4 + 0.3 * $goldBlend));
                    $cB = (int) ($ringBright * (0.15 + 0.1 * $goldBlend));
                    echo Theme::moveTo($rRow, $rCol)
                        .Theme::rgb($cR, $cG, $cB).'·'.$r;
                    $ringCells[] = ['row' => $rRow, 'col' => $rCol];
                }
            }

            usleep(25000);

            foreach ($ringCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 4 — Title (~0.7s).
     *
     * "L E G I O N" fades in through a gold gradient.
     * Subtitle "⟡ Five minds, one decree ⟡" types out below.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'L E G I O N';
        $subtitle = '⟡ Five minds, one decree ⟡';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $titleRow = $this->cy;

        // Gold gradient fade-in
        $gradient = [
            [40, 35, 10],
            [100, 80, 25],
            [160, 130, 38],
            [200, 165, 48],
            [220, 180, 50],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            if ($titleRow >= 1 && $titleRow <= $this->termHeight) {
                echo Theme::moveTo($titleRow, $titleCol)
                    .Theme::rgb($rv, $g, $b).$title.$r;
            }
            usleep(45000);
        }

        // Subtitle typeout
        $subRow = $titleRow + 2;
        usleep(80000);

        if ($subRow >= 1 && $subRow <= $this->termHeight) {
            $subColor = Theme::rgb(180, 160, 120);
            echo Theme::moveTo($subRow, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo $subColor.$char.$r;
                usleep(18000);
            }
        }

        usleep(500000);
    }
}
