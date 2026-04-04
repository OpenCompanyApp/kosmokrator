<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Wax seal and parchment animation for the :release command.
 *
 * Three phases: parchment scroll appears, wax seal stamps down,
 * golden ribbon unfurls with title reveal. ~3 seconds total.
 */
class AnsiRelease implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const PARCHMENT_BORDER_H = ['─', '═'];

    private const PARCHMENT_BORDER_V = ['│', '║'];

    private const SEAL_RING = ['●', '○', '◉', '◎'];

    private const RIBBON_CHARS = ['═', '─', '~', '≈'];

    /**
     * Run the full release seal animation (scroll -> seal -> title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseScroll();
        $this->phaseSeal();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Scroll (~0.8s).
     *
     * A parchment-like rectangle appears at center with a box-drawing border.
     * Faint horizontal lines inside represent document text. The document
     * scrolls upward slightly as it materializes.
     */
    private function phaseScroll(): void
    {
        $r = Theme::reset();

        // Parchment colors
        $parchment = Theme::rgb(200, 180, 140);
        $parchmentDim = Theme::rgb(140, 120, 90);
        $textDim = Theme::rgb(120, 110, 90);

        // Parchment dimensions
        $docWidth = min(40, $this->termWidth - 10);
        $docHeight = min(16, $this->termHeight - 8);
        $startCol = max(1, $this->cx - (int) ($docWidth / 2));

        $totalSteps = 20;

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Scroll offset: starts 3 rows lower, settles to final position
            $scrollOffset = (int) (3 * (1.0 - $progress));
            $startRow = max(1, $this->cy - (int) ($docHeight / 2) + $scrollOffset);

            // Reveal height grows as it materializes
            $visibleHeight = max(1, (int) ($docHeight * min(1.0, $progress * 1.5)));

            // Fade factor: dim -> bright
            $fade = min(1.0, $progress * 1.3);
            $borderR = (int) (200 * $fade);
            $borderG = (int) (180 * $fade);
            $borderB = (int) (140 * $fade);
            $borderColor = Theme::rgb($borderR, $borderG, $borderB);

            for ($dy = 0; $dy < $visibleHeight; $dy++) {
                $row = $startRow + $dy;
                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                $isTop = ($dy === 0);
                $isBottom = ($dy === $visibleHeight - 1 && $progress > 0.6);

                if ($isTop) {
                    // Top border
                    $topLeft = '╔';
                    $topRight = '╗';
                    $col = $startCol;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.$topLeft.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                    for ($dx = 1; $dx < $docWidth - 1; $dx++) {
                        $col = $startCol + $dx;
                        if ($col >= 1 && $col < $this->termWidth) {
                            echo Theme::moveTo($row, $col).$borderColor.'═'.$r;
                            $this->prevCells[] = ['row' => $row, 'col' => $col];
                        }
                    }
                    $col = $startCol + $docWidth - 1;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.$topRight.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                } elseif ($isBottom) {
                    // Bottom border
                    $col = $startCol;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.'╚'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                    for ($dx = 1; $dx < $docWidth - 1; $dx++) {
                        $col = $startCol + $dx;
                        if ($col >= 1 && $col < $this->termWidth) {
                            echo Theme::moveTo($row, $col).$borderColor.'═'.$r;
                            $this->prevCells[] = ['row' => $row, 'col' => $col];
                        }
                    }
                    $col = $startCol + $docWidth - 1;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.'╝'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                } else {
                    // Side borders + interior content
                    $col = $startCol;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.'║'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                    $col = $startCol + $docWidth - 1;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$borderColor.'║'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }

                    // Interior text lines (dim horizontal dashes as placeholder text)
                    if ($dy % 2 === 0 && $progress > 0.3) {
                        $lineStart = $startCol + 3;
                        $lineEnd = $startCol + $docWidth - 4;
                        // Vary line lengths for realism
                        $lineActualEnd = $lineEnd - rand(0, (int) (($lineEnd - $lineStart) * 0.4));
                        $textFade = min(1.0, ($progress - 0.3) * 2.0);
                        $tR = (int) (120 * $textFade);
                        $tG = (int) (110 * $textFade);
                        $tB = (int) (90 * $textFade);
                        $lineColor = Theme::rgb($tR, $tG, $tB);

                        for ($lx = $lineStart; $lx <= $lineActualEnd; $lx++) {
                            if ($lx >= 1 && $lx < $this->termWidth) {
                                $char = (rand(0, 3) === 0) ? '·' : '─';
                                echo Theme::moveTo($row, $lx).$lineColor.$char.$r;
                                $this->prevCells[] = ['row' => $row, 'col' => $lx];
                            }
                        }
                    }
                }
            }

            usleep(40000);
        }

        // Keep the final parchment drawn — don't erase. Store the cells for the seal phase.
    }

    /**
     * Phase 2 — Seal (~1s).
     *
     * A circular wax seal descends from above and stamps onto the center
     * of the parchment. Impact flash on contact. The seal is built from
     * concentric ring characters in deep red.
     */
    private function phaseSeal(): void
    {
        $r = Theme::reset();
        $waxRed = Theme::rgb(180, 30, 30);
        $waxBright = Theme::rgb(230, 60, 50);
        $waxDark = Theme::rgb(120, 20, 20);
        $flashWhite = Theme::rgb(255, 255, 255);

        // Seal radius
        $sealRadius = 3;
        $sealTargetRow = $this->cy;
        $sealCol = $this->cx;

        // Seal pattern (relative to center)
        $sealPattern = $this->buildSealPattern($sealRadius);

        // Descent: seal comes down from above
        $descentSteps = 15;
        $startRow = max(1, $sealTargetRow - 10);

        for ($step = 0; $step < $descentSteps; $step++) {
            $progress = $step / $descentSteps;
            // Ease-out for a "stamp" feel — fast start, slows near target
            $eased = 1.0 - (1.0 - $progress) * (1.0 - $progress);

            $currentRow = (int) ($startRow + $eased * ($sealTargetRow - $startRow));

            // Erase previous seal position (only seal cells, not parchment)
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Draw seal at current position
            $sealColor = ($step < $descentSteps - 1) ? $waxRed : $waxBright;
            foreach ($sealPattern as [$dy, $dx, $char]) {
                $row = $currentRow + $dy;
                $col = $sealCol + $dx;
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$sealColor.$char.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }

            usleep(35000);
        }

        // Impact flash: briefly turn seal white then back to red
        foreach ($sealPattern as [$dy, $dx, $char]) {
            $row = $sealTargetRow + $dy;
            $col = $sealCol + $dx;
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                echo Theme::moveTo($row, $col).$flashWhite.$char.$r;
            }
        }
        usleep(80000);

        // Radial flash ring expanding out from seal
        for ($ring = 1; $ring <= 5; $ring++) {
            $ringBrightness = max(40, 255 - $ring * 50);
            $ringColor = Theme::rgb($ringBrightness, (int) ($ringBrightness * 0.3), (int) ($ringBrightness * 0.3));
            $flashCells = [];

            for ($angle = 0; $angle < 360; $angle += 20) {
                $rad = deg2rad($angle);
                $flashRadius = $sealRadius + $ring;
                $dy = (int) round(sin($rad) * $flashRadius * 0.5); // Half vertical for aspect ratio
                $dx = (int) round(cos($rad) * $flashRadius);
                $row = $sealTargetRow + $dy;
                $col = $sealCol + $dx;
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$ringColor.'·'.$r;
                    $flashCells[] = ['row' => $row, 'col' => $col];
                }
            }
            usleep(30000);

            // Erase flash ring
            foreach ($flashCells as ['row' => $fr, 'col' => $fc]) {
                if ($fr >= 1 && $fr <= $this->termHeight && $fc >= 1 && $fc < $this->termWidth) {
                    echo Theme::moveTo($fr, $fc).' ';
                }
            }
        }

        // Redraw seal in final color with shading
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        foreach ($sealPattern as [$dy, $dx, $char]) {
            $row = $sealTargetRow + $dy;
            $col = $sealCol + $dx;
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                // Darker at edges, brighter in center
                $dist = sqrt($dy * $dy + $dx * $dx * 0.25);
                $centerFade = min(1.0, $dist / ($sealRadius + 1));
                $sR = (int) (180 + (1.0 - $centerFade) * 50);
                $sG = (int) (30 + (1.0 - $centerFade) * 30);
                $sB = (int) (30 + (1.0 - $centerFade) * 20);
                echo Theme::moveTo($row, $col)
                    .Theme::rgb(min(255, $sR), min(255, $sG), min(255, $sB))
                    .$char.$r;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
        }

        usleep(150000);
    }

    /**
     * Build a circular seal pattern as relative coordinate+character triples.
     *
     * @return array<int, array{0: int, 1: int, 2: string}> [dy, dx, char]
     */
    private function buildSealPattern(int $radius): array
    {
        $pattern = [];

        // Center
        $pattern[] = [0, 0, '◉'];

        // Inner ring
        for ($angle = 0; $angle < 360; $angle += 45) {
            $rad = deg2rad($angle);
            $dy = (int) round(sin($rad) * 1.0);
            $dx = (int) round(cos($rad) * 1.5);
            $pattern[] = [$dy, $dx, '●'];
        }

        // Outer ring
        for ($angle = 0; $angle < 360; $angle += 25) {
            $rad = deg2rad($angle);
            $dy = (int) round(sin($rad) * ($radius - 0.5));
            $dx = (int) round(cos($rad) * $radius);
            $pattern[] = [$dy, $dx, '○'];
        }

        // Fill gaps with block chars
        for ($angle = 0; $angle < 360; $angle += 30) {
            $rad = deg2rad($angle);
            $dy = (int) round(sin($rad) * ($radius * 0.6));
            $dx = (int) round(cos($rad) * ($radius * 0.8));
            $pattern[] = [$dy, $dx, '●'];
        }

        return $pattern;
    }

    /**
     * Phase 3 — Title (~1.2s).
     *
     * Golden ribbon unfurls horizontally below the seal. "R E L E A S E"
     * fades in with a red-to-golden gradient. Subtitle typeout below.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();

        // Ribbon unfurl below the seal
        $ribbonRow = $this->cy + 5;
        $ribbonWidth = min(30, $this->termWidth - 10);
        $ribbonStartCol = max(1, $this->cx - (int) ($ribbonWidth / 2));
        $golden = Theme::rgb(255, 200, 80);
        $goldenDim = Theme::rgb(180, 140, 50);

        // Unfurl from center outward
        $halfWidth = (int) ($ribbonWidth / 2);
        for ($expand = 0; $expand <= $halfWidth; $expand++) {
            $leftCol = $this->cx - $expand;
            $rightCol = $this->cx + $expand;

            if ($ribbonRow >= 1 && $ribbonRow <= $this->termHeight) {
                if ($leftCol >= 1 && $leftCol < $this->termWidth) {
                    $fadeRatio = $expand / max(1, $halfWidth);
                    $rVal = (int) (255 - $fadeRatio * 75);
                    $gVal = (int) (200 - $fadeRatio * 60);
                    $bVal = (int) (80 - $fadeRatio * 30);
                    echo Theme::moveTo($ribbonRow, $leftCol)
                        .Theme::rgb($rVal, $gVal, $bVal).'═'.$r;
                    $this->prevCells[] = ['row' => $ribbonRow, 'col' => $leftCol];
                }
                if ($rightCol >= 1 && $rightCol < $this->termWidth && $rightCol !== $leftCol) {
                    echo Theme::moveTo($ribbonRow, $rightCol)
                        .$golden.'═'.$r;
                    $this->prevCells[] = ['row' => $ribbonRow, 'col' => $rightCol];
                }
            }

            usleep(18000);
        }

        // Ribbon end caps
        $capLeft = $ribbonStartCol;
        $capRight = $ribbonStartCol + $ribbonWidth - 1;
        if ($ribbonRow >= 1 && $ribbonRow <= $this->termHeight) {
            if ($capLeft >= 1 && $capLeft < $this->termWidth) {
                echo Theme::moveTo($ribbonRow, $capLeft).$goldenDim.'╘'.$r;
            }
            if ($capRight >= 1 && $capRight < $this->termWidth) {
                echo Theme::moveTo($ribbonRow, $capRight).$goldenDim.'╛'.$r;
            }
        }

        usleep(100000);

        // Title: "R E L E A S E" fade in red -> golden
        $title = 'R E L E A S E';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $titleRow = $this->cy + 7;

        $titleGradient = [
            [180, 30, 30],    // Deep red
            [200, 50, 35],
            [220, 80, 40],
            [235, 120, 50],
            [245, 150, 60],
            [250, 175, 70],
            [255, 190, 75],
            [255, 200, 80],   // Golden
        ];

        foreach ($titleGradient as [$rv, $gv, $bv]) {
            if ($titleRow >= 1 && $titleRow <= $this->termHeight) {
                echo Theme::moveTo($titleRow, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(50000);
        }

        // Subtitle typeout
        $subtitle = '⊛ Sealed & shipped ⊛';
        $subLen = mb_strwidth($subtitle);
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $subRow = $titleRow + 2;

        usleep(100000);

        if ($subRow >= 1 && $subRow <= $this->termHeight) {
            echo Theme::moveTo($subRow, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo Theme::rgb(255, 200, 80).$char.$r;
                usleep(25000);
            }
        }

        usleep(500000);
    }
}
