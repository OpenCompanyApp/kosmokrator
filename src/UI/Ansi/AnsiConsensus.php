<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Council deliberation animation for the :consensus command.
 *
 * Four phases: three council figures appear, thought particles clash,
 * converge to agreement, and title reveals. ~3.5 seconds total.
 */
class AnsiConsensus implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    /** Council member colors [R, G, B]. */
    private const PLANNER_COLOR = [80, 140, 255];

    private const ARCHITECT_COLOR = [180, 100, 255];

    private const CRITIC_COLOR = [255, 180, 50];

    private const DEBATE_CHARS = ['·', '∗', '✦', '•', '◦'];

    private const CONVERGENCE_CHARS = ['✦', '∗', '·', '◦'];

    /**
     * Run the full consensus council animation (council -> debate -> convergence -> title).
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
            $this->phaseCouncil();
            $this->phaseDebate();
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
     * Phase 1 — Council (~0.7s).
     *
     * Three figure glyphs appear in a triangle formation at center.
     * Planner (circled-plus) left, Architect (gemstone) right,
     * Critic (circled-x) top. Labels appear below each figure.
     */
    private function phaseCouncil(): void
    {
        $r = Theme::reset();

        // Triangle positions relative to center
        $figures = [
            [
                'glyph' => '⊕',
                'label' => 'PLAN',
                'color' => self::PLANNER_COLOR,
                'row' => $this->cy + 2,
                'col' => $this->cx - 12,
            ],
            [
                'glyph' => '◈',
                'label' => 'ARCH',
                'color' => self::ARCHITECT_COLOR,
                'row' => $this->cy + 2,
                'col' => $this->cx + 12,
            ],
            [
                'glyph' => '⊗',
                'label' => 'CRIT',
                'color' => self::CRITIC_COLOR,
                'row' => $this->cy - 4,
                'col' => $this->cx,
            ],
        ];

        // Fade in each figure sequentially with a glow effect
        foreach ($figures as $figure) {
            $glowSteps = [
                [0.3, 0.3, 0.3],  // Dim
                [0.5, 0.5, 0.5],
                [0.7, 0.7, 0.7],
                [1.0, 1.0, 1.0],  // Full
            ];

            foreach ($glowSteps as [$rMul, $gMul, $bMul]) {
                $fR = (int) ($figure['color'][0] * $rMul);
                $fG = (int) ($figure['color'][1] * $gMul);
                $fB = (int) ($figure['color'][2] * $bMul);
                $color = Theme::rgb($fR, $fG, $fB);

                $row = $figure['row'];
                $col = $figure['col'];

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.$figure['glyph'].$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }

                usleep(30000);
            }

            // Draw label below figure
            $labelRow = $figure['row'] + 1;
            $labelLen = mb_strwidth($figure['label']);
            $labelCol = max(1, $figure['col'] - (int) ($labelLen / 2));

            if ($labelRow >= 1 && $labelRow <= $this->termHeight) {
                $dimR = (int) ($figure['color'][0] * 0.6);
                $dimG = (int) ($figure['color'][1] * 0.6);
                $dimB = (int) ($figure['color'][2] * 0.6);
                $labelColor = Theme::rgb($dimR, $dimG, $dimB);

                for ($i = 0; $i < $labelLen; $i++) {
                    $lCol = $labelCol + $i;
                    if ($lCol >= 1 && $lCol < $this->termWidth) {
                        echo Theme::moveTo($labelRow, $lCol)
                            .$labelColor.mb_substr($figure['label'], $i, 1).$r;
                        $this->prevCells[] = ['row' => $labelRow, 'col' => $lCol];
                    }
                }
            }

            usleep(80000);
        }

        usleep(150000);
    }

    /**
     * Phase 2 — Debate (~0.8s).
     *
     * Thought bubble particles emit from each figure toward center.
     * Colors mix at collision points. Brief flashes of conflict as
     * particles bounce off each other.
     */
    private function phaseDebate(): void
    {
        $r = Theme::reset();

        // Source positions (figure positions)
        $sources = [
            ['row' => $this->cy + 2, 'col' => $this->cx - 12, 'color' => self::PLANNER_COLOR],
            ['row' => $this->cy + 2, 'col' => $this->cx + 12, 'color' => self::ARCHITECT_COLOR],
            ['row' => $this->cy - 4, 'col' => $this->cx,      'color' => self::CRITIC_COLOR],
        ];

        $centerRow = $this->cy;
        $centerCol = $this->cx;

        $totalSteps = 20;

        // Track debate particles separately from prevCells (figures stay)
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
                $particleCount = (int) (3 + $progress * 5);

                for ($p = 0; $p < $particleCount; $p++) {
                    // Particle position: interpolate from source to center with randomness
                    $t = rand(10, 90) / 100.0;
                    $pRow = (int) ($source['row'] + $t * ($centerRow - $source['row']) + rand(-2, 2));
                    $pCol = (int) ($source['col'] + $t * ($centerCol - $source['col']) + rand(-3, 3));

                    if ($pRow < 1 || $pRow > $this->termHeight || $pCol < 1 || $pCol >= $this->termWidth) {
                        continue;
                    }

                    // Color fades toward white at center
                    $centerDist = sqrt(pow($pRow - $centerRow, 2) + pow(($pCol - $centerCol) * 0.5, 2));
                    $maxDist = 15.0;
                    $colorBlend = min(1.0, $centerDist / $maxDist);

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
                    $fRow = $centerRow + rand(-1, 1);
                    $fCol = $centerCol + rand(-2, 2);
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
     * Particles slow down and converge to a single bright point at center.
     * Colors blend to white. The three figures dim as the consensus
     * point brightens. A radiating ring expands outward.
     */
    private function phaseConvergence(): void
    {
        $r = Theme::reset();

        $centerRow = $this->cy;
        $centerCol = $this->cx;

        // Convergence: particles spiral inward
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
            $particleCount = max(3, (int) (12 * (1.0 - $progress * 0.5)));

            for ($p = 0; $p < $particleCount; $p++) {
                $angle = rand(0, 359);
                $rad = deg2rad($angle);
                $dist = rand(10, (int) ($cloudRadius * 100)) / 100.0;
                $pRow = (int) ($centerRow + sin($rad) * $dist);
                $pCol = (int) ($centerCol + cos($rad) * $dist * 2); // Wider horizontal

                if ($pRow < 1 || $pRow > $this->termHeight || $pCol < 1 || $pCol >= $this->termWidth) {
                    continue;
                }

                // Color converges to white
                $whiteBlend = $progress;
                $baseColors = [self::PLANNER_COLOR, self::ARCHITECT_COLOR, self::CRITIC_COLOR];
                $baseColor = $baseColors[array_rand($baseColors)];
                $pR = (int) ($baseColor[0] * (1.0 - $whiteBlend) + 255 * $whiteBlend);
                $pG = (int) ($baseColor[1] * (1.0 - $whiteBlend) + 255 * $whiteBlend);
                $pB = (int) ($baseColor[2] * (1.0 - $whiteBlend) + 255 * $whiteBlend);

                $char = self::CONVERGENCE_CHARS[array_rand(self::CONVERGENCE_CHARS)];
                echo Theme::moveTo($pRow, $pCol)
                    .Theme::rgb(min(255, $pR), min(255, $pG), min(255, $pB))
                    .$char.$r;
                $convergeCells[] = ['row' => $pRow, 'col' => $pCol];
            }

            // Dim the council figures progressively
            $figDim = max(0.2, 1.0 - $progress * 0.8);
            $figures = [
                ['glyph' => '⊕', 'color' => self::PLANNER_COLOR, 'row' => $this->cy + 2, 'col' => $this->cx - 12],
                ['glyph' => '◈', 'color' => self::ARCHITECT_COLOR, 'row' => $this->cy + 2, 'col' => $this->cx + 12],
                ['glyph' => '⊗', 'color' => self::CRITIC_COLOR, 'row' => $this->cy - 4, 'col' => $this->cx],
            ];
            foreach ($figures as $fig) {
                $fRow = $fig['row'];
                $fCol = $fig['col'];
                if ($fRow >= 1 && $fRow <= $this->termHeight && $fCol >= 1 && $fCol < $this->termWidth) {
                    $fR = (int) ($fig['color'][0] * $figDim);
                    $fG = (int) ($fig['color'][1] * $figDim);
                    $fB = (int) ($fig['color'][2] * $figDim);
                    echo Theme::moveTo($fRow, $fCol)
                        .Theme::rgb($fR, $fG, $fB).$fig['glyph'].$r;
                }
            }

            // Brightening center point
            if ($progress > 0.3) {
                $centerBright = min(255, (int) (80 + $progress * 200));
                if ($centerRow >= 1 && $centerRow <= $this->termHeight
                    && $centerCol >= 1 && $centerCol < $this->termWidth) {
                    echo Theme::moveTo($centerRow, $centerCol)
                        .Theme::rgb($centerBright, $centerBright, $centerBright).'◉'.$r;
                    $convergeCells[] = ['row' => $centerRow, 'col' => $centerCol];
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

        // Final bright center point
        if ($centerRow >= 1 && $centerRow <= $this->termHeight
            && $centerCol >= 1 && $centerCol < $this->termWidth) {
            echo Theme::moveTo($centerRow, $centerCol)
                .Theme::rgb(255, 255, 255).'◉'.$r;
        }

        usleep(80000);

        // Radiating ring expanding from center
        for ($ring = 1; $ring <= 8; $ring++) {
            $ringCells = [];
            $ringBright = max(40, 255 - $ring * 30);

            for ($angle = 0; $angle < 360; $angle += 12) {
                $rad = deg2rad($angle);
                $rRow = (int) ($centerRow + sin($rad) * $ring * 0.6);
                $rCol = (int) ($centerCol + cos($rad) * $ring * 1.2);

                if ($rRow >= 1 && $rRow <= $this->termHeight && $rCol >= 1 && $rCol < $this->termWidth) {
                    echo Theme::moveTo($rRow, $rCol)
                        .Theme::rgb($ringBright, $ringBright, $ringBright).'·'.$r;
                    $ringCells[] = ['row' => $rRow, 'col' => $rCol];
                }
            }

            usleep(25000);

            // Erase ring
            foreach ($ringCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
        }

        usleep(100000);
    }

    /**
     * Phase 4 — Title (~1.2s).
     *
     * "C O N S E N S U S" fades in white. Subtitle "Alignment reached"
     * types out below.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        // Subtle background: faint traces of the three colors
        $traceCount = (int) ($this->termWidth * $this->termHeight * 0.006);
        $traceColors = [self::PLANNER_COLOR, self::ARCHITECT_COLOR, self::CRITIC_COLOR];
        for ($i = 0; $i < $traceCount; $i++) {
            $sr = rand(1, $this->termHeight);
            $sc = rand(1, $this->termWidth - 1);
            if ($sr >= 1 && $sr <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth) {
                $tc = $traceColors[array_rand($traceColors)];
                $dim = 0.15 + (rand(0, 20) / 100.0);
                $tR = (int) ($tc[0] * $dim);
                $tG = (int) ($tc[1] * $dim);
                $tB = (int) ($tc[2] * $dim);
                echo Theme::moveTo($sr, $sc)
                    .Theme::rgb($tR, $tG, $tB).'·'.$r;
            }
        }

        // Title: "C O N S E N S U S" fade to white
        $title = 'C O N S E N S U S';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $titleRow = $this->cy;

        $whiteGradient = [
            [40, 40, 40],
            [70, 70, 70],
            [100, 100, 100],
            [135, 135, 135],
            [170, 170, 170],
            [200, 200, 200],
            [230, 230, 230],
            [255, 255, 255],
        ];

        foreach ($whiteGradient as [$rv, $gv, $bv]) {
            if ($titleRow >= 1 && $titleRow <= $this->termHeight) {
                echo Theme::moveTo($titleRow, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(50000);
        }

        // Subtitle typeout
        $subtitle = '◈ Alignment reached ◈';
        $subLen = mb_strwidth($subtitle);
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $subRow = $titleRow + 2;

        usleep(100000);

        if ($subRow >= 1 && $subRow <= $this->termHeight) {
            echo Theme::moveTo($subRow, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo Theme::rgb(200, 200, 210).$char.$r;
                usleep(25000);
            }
        }

        usleep(500000);
    }
}
