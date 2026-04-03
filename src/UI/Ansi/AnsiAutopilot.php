<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Spacecraft launch sequence animation for the :autopilot command.
 *
 * Four phases: countdown, ignition, ascent through starfield, and
 * trajectory arc with title reveal. ~4 seconds total.
 */
class AnsiAutopilot implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const FLAME_CHARS = ['▓', '█', '◆', '✦', '∗'];

    private const STAR_CHARS = ['.', '·', '✧'];

    private const SPEED_CHARS = ['│', '║'];

    private const BLOCK_CHARS = ['█', '▓', '▒', '░'];

    /**
     * Run the full autopilot launch animation (countdown -> ignition -> ascent -> trajectory).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseCountdown();
        $this->phaseIgnition();
        $this->phaseAscent();
        $this->phaseTrajectory();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Countdown (~1.2s).
     *
     * Numbers 5 through 1 appear large at center using blocky characters,
     * each replacing the last with a pulse/scale effect. "LAUNCH SEQUENCE"
     * header displayed above.
     */
    private function phaseCountdown(): void
    {
        $r = Theme::reset();

        // Big digit patterns (5x5 block font)
        $digits = [
            5 => [
                '█████',
                '█    ',
                '█████',
                '    █',
                '█████',
            ],
            4 => [
                '█   █',
                '█   █',
                '█████',
                '    █',
                '    █',
            ],
            3 => [
                '█████',
                '    █',
                '█████',
                '    █',
                '█████',
            ],
            2 => [
                '█████',
                '    █',
                '█████',
                '█    ',
                '█████',
            ],
            1 => [
                '  █  ',
                ' ██  ',
                '  █  ',
                '  █  ',
                '█████',
            ],
        ];

        // "LAUNCH SEQUENCE" header
        $header = 'L A U N C H   S E Q U E N C E';
        $headerLen = mb_strwidth($header);
        $headerCol = max(1, (int) (($this->termWidth - $headerLen) / 2));
        $headerRow = max(1, $this->cy - 7);

        // Fade in header
        $headerGradient = [
            [40, 40, 40], [80, 80, 80], [140, 140, 140], [200, 200, 200], [240, 240, 240],
        ];
        foreach ($headerGradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($headerRow, $headerCol)
                .Theme::rgb($rv, $gv, $bv).$header.$r;
            usleep(30000);
        }

        // Display each digit with pulse effect
        foreach ($digits as $num => $lines) {
            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Each digit line is 5 chars wide, scale up by 2x horizontal
            $digitWidth = 10; // 5 chars * 2
            $digitHeight = 5;
            $startCol = max(1, $this->cx - (int) ($digitWidth / 2));
            $startRow = max(1, $this->cy - (int) ($digitHeight / 2));

            // Pulse: 3 sub-frames per digit — dim -> bright -> hold
            $pulseColors = [
                [120, 120, 120],
                [200, 200, 200],
                [255, 255, 255],
            ];

            foreach ($pulseColors as $pi => [$rv, $gv, $bv]) {
                $color = Theme::rgb($rv, $gv, $bv);

                foreach ($lines as $lineIdx => $line) {
                    $row = $startRow + $lineIdx;
                    if ($row < 1 || $row > $this->termHeight) {
                        continue;
                    }
                    $chars = mb_str_split($line);
                    foreach ($chars as $charIdx => $char) {
                        // Scale horizontally by 2
                        for ($sx = 0; $sx < 2; $sx++) {
                            $col = $startCol + $charIdx * 2 + $sx;
                            if ($col < 1 || $col >= $this->termWidth) {
                                continue;
                            }
                            if ($char !== ' ') {
                                // Use different block chars for texture
                                $blockChar = self::BLOCK_CHARS[min($pi, count(self::BLOCK_CHARS) - 1)];
                                echo Theme::moveTo($row, $col).$color.$blockChar.$r;
                                $this->prevCells[] = ['row' => $row, 'col' => $col];
                            }
                        }
                    }
                }

                usleep($pi < 2 ? 35000 : 100000);
            }

            // Brief hold before next digit
            usleep(60000);
        }

        // Final erase of last digit
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        // Erase header
        echo Theme::moveTo($headerRow, $headerCol).str_repeat(' ', $headerLen);

        usleep(60000);
    }

    /**
     * Phase 2 — Ignition (~0.8s).
     *
     * Bottom-center erupts with flame particles expanding upward.
     * White core transitioning to orange and red at the edges.
     */
    private function phaseIgnition(): void
    {
        $r = Theme::reset();
        $baseRow = $this->termHeight;
        $baseCol = $this->cx;
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

            // Expanding flame plume from bottom center
            $plumeHeight = (int) (3 + $progress * ($this->termHeight * 0.5));
            $plumeWidth = (int) (2 + $progress * 12);

            for ($py = 0; $py < $plumeHeight; $py++) {
                $row = $baseRow - $py;
                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                // Width narrows toward the top of the plume
                $heightRatio = $py / max(1, $plumeHeight);
                $widthAtHeight = max(1, (int) ($plumeWidth * (1.0 - $heightRatio * 0.6)));

                $particlesAtRow = max(1, (int) ($widthAtHeight * 1.5 * (1.0 - $heightRatio * 0.5)));

                for ($p = 0; $p < $particlesAtRow; $p++) {
                    $col = $baseCol + rand(-$widthAtHeight, $widthAtHeight);
                    if ($col < 1 || $col >= $this->termWidth) {
                        continue;
                    }

                    // Color: white core near bottom -> orange -> red at edges/top
                    $distFromCenter = abs($col - $baseCol) / max(1, $plumeWidth);
                    $verticalFade = $heightRatio;

                    if ($verticalFade < 0.2 && $distFromCenter < 0.3) {
                        // White-hot core
                        $red = 255;
                        $green = (int) (220 - $verticalFade * 200);
                        $blue = (int) (200 - $verticalFade * 400);
                    } elseif ($verticalFade < 0.5) {
                        // Orange mid-zone
                        $red = 255;
                        $green = (int) (140 - $verticalFade * 180);
                        $blue = 0;
                    } else {
                        // Red outer edges
                        $red = max(80, (int) (220 - $verticalFade * 180));
                        $green = max(0, (int) (40 - $verticalFade * 60));
                        $blue = 0;
                    }

                    echo Theme::moveTo($row, $col)
                        .Theme::rgb(max(0, min(255, $red)), max(0, min(255, $green)), max(0, min(255, $blue)))
                        .self::FLAME_CHARS[array_rand(self::FLAME_CHARS)]
                        .$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }

            usleep(40000);
        }

        // Final erase
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];
    }

    /**
     * Phase 3 — Ascent (~1s).
     *
     * Rocket glyph rises from bottom to top, trailing fire particles.
     * Stars appear in background, speed lines streak past.
     */
    private function phaseAscent(): void
    {
        $r = Theme::reset();
        $totalSteps = 30;

        // Pre-generate fixed star positions
        $starCount = (int) ($this->termWidth * $this->termHeight * 0.012);
        $stars = [];
        for ($i = 0; $i < $starCount; $i++) {
            $stars[] = [
                'row' => rand(1, $this->termHeight),
                'col' => rand(1, $this->termWidth - 1),
                'char' => self::STAR_CHARS[array_rand(self::STAR_CHARS)],
                'brightness' => rand(60, 180),
            ];
        }

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;
            // Ease-in for acceleration feel
            $eased = $progress * $progress;

            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Rocket position: bottom to top
            $rocketRow = (int) ($this->termHeight - $eased * ($this->termHeight + 2));
            $rocketCol = $this->cx;

            // Draw stars (some flicker)
            foreach ($stars as $star) {
                $sr = $star['row'];
                $sc = $star['col'];
                if ($sr >= 1 && $sr <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth) {
                    // Don't draw stars near the rocket
                    if (abs($sr - $rocketRow) < 4 && abs($sc - $rocketCol) < 6) {
                        continue;
                    }
                    $flicker = $star['brightness'] + rand(-20, 20);
                    $b = max(30, min(220, $flicker));
                    echo Theme::moveTo($sr, $sc)
                        .Theme::rgb($b, $b, $b)
                        .$star['char'].$r;
                    $this->prevCells[] = ['row' => $sr, 'col' => $sc];
                }
            }

            // Speed lines — vertical streaks moving downward (relative to rocket)
            if ($step > 5) {
                $lineCount = min(12, (int) ($progress * 16));
                for ($l = 0; $l < $lineCount; $l++) {
                    $lineCol = $rocketCol + rand(-20, 20);
                    $lineRow = rand(max(1, $rocketRow - 3), min($this->termHeight, $rocketRow + 8));
                    $lineLen = rand(2, 4 + (int) ($progress * 3));
                    for ($s = 0; $s < $lineLen; $s++) {
                        $lr = $lineRow + $s;
                        if ($lr >= 1 && $lr <= $this->termHeight && $lineCol >= 1 && $lineCol < $this->termWidth) {
                            $fade = $s / max(1, $lineLen);
                            $brightness = max(30, (int) (160 * (1.0 - $fade)));
                            echo Theme::moveTo($lr, $lineCol)
                                .Theme::rgb($brightness, $brightness, $brightness)
                                .self::SPEED_CHARS[array_rand(self::SPEED_CHARS)]
                                .$r;
                            $this->prevCells[] = ['row' => $lr, 'col' => $lineCol];
                        }
                    }
                }
            }

            // Draw rocket if still on screen
            if ($rocketRow >= 1 && $rocketRow <= $this->termHeight) {
                // Rocket nose
                if ($rocketCol >= 1 && $rocketCol < $this->termWidth) {
                    echo Theme::moveTo($rocketRow, $rocketCol)
                        .Theme::rgb(255, 255, 255).'▲'.$r;
                    $this->prevCells[] = ['row' => $rocketRow, 'col' => $rocketCol];
                }
                // Rocket body
                if ($rocketRow + 1 >= 1 && $rocketRow + 1 <= $this->termHeight && $rocketCol >= 1 && $rocketCol < $this->termWidth) {
                    echo Theme::moveTo($rocketRow + 1, $rocketCol)
                        .Theme::rgb(220, 220, 230).'║'.$r;
                    $this->prevCells[] = ['row' => $rocketRow + 1, 'col' => $rocketCol];
                }
                if ($rocketRow + 2 >= 1 && $rocketRow + 2 <= $this->termHeight && $rocketCol >= 1 && $rocketCol < $this->termWidth) {
                    echo Theme::moveTo($rocketRow + 2, $rocketCol)
                        .Theme::rgb(200, 200, 210).'║'.$r;
                    $this->prevCells[] = ['row' => $rocketRow + 2, 'col' => $rocketCol];
                }
            }

            // Exhaust trail below rocket
            $trailStart = $rocketRow + 3;
            $trailLen = (int) (3 + $progress * 10);
            for ($t = 0; $t < $trailLen; $t++) {
                $tr = $trailStart + $t;
                if ($tr < 1 || $tr > $this->termHeight) {
                    continue;
                }
                $spread = max(0, (int) ($t * 0.4));
                $tc = $rocketCol + rand(-$spread, $spread);
                if ($tc < 1 || $tc >= $this->termWidth) {
                    continue;
                }

                $fadeRatio = $t / max(1, $trailLen);
                if ($fadeRatio < 0.3) {
                    // White core
                    $red = 255;
                    $green = (int) (240 - $fadeRatio * 300);
                    $blue = (int) (200 - $fadeRatio * 600);
                } else {
                    // Orange -> red fade
                    $red = max(60, (int) (255 - $fadeRatio * 150));
                    $green = max(0, (int) (140 - $fadeRatio * 200));
                    $blue = 0;
                }

                echo Theme::moveTo($tr, $tc)
                    .Theme::rgb(max(0, min(255, $red)), max(0, min(255, $green)), max(0, min(255, $blue)))
                    .self::FLAME_CHARS[array_rand(self::FLAME_CHARS)]
                    .$r;
                $this->prevCells[] = ['row' => $tr, 'col' => $tc];
            }

            usleep(33000);
        }

        // Final erase
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];
    }

    /**
     * Phase 4 — Trajectory (~1s).
     *
     * Rocket disappears off top. A curved dotted arc draws from bottom-center
     * to upper-right. Waypoint markers appear along the arc. Title
     * "A U T O P I L O T" fades in cyan, subtitle "☄ Course plotted ☄".
     */
    private function phaseTrajectory(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        // Draw some background stars
        $starCount = (int) ($this->termWidth * $this->termHeight * 0.008);
        $starPositions = [];
        for ($i = 0; $i < $starCount; $i++) {
            $sr = rand(1, $this->termHeight);
            $sc = rand(1, $this->termWidth - 1);
            $b = rand(40, 120);
            echo Theme::moveTo($sr, $sc)
                .Theme::rgb($b, $b, $b)
                .self::STAR_CHARS[array_rand(self::STAR_CHARS)].$r;
            $starPositions[] = ['row' => $sr, 'col' => $sc];
        }

        // Draw trajectory arc from bottom-center to upper-right
        // Using a parametric curve: quadratic bezier
        $startX = (float) $this->cx;
        $startY = (float) ($this->termHeight - 2);
        $controlX = (float) ($this->cx + (int) ($this->termWidth * 0.15));
        $controlY = (float) ($this->cy - 2);
        $endX = (float) ($this->cx + (int) ($this->termWidth * 0.35));
        $endY = 3.0;

        $arcSteps = 30;
        $arcPositions = [];

        // Draw arc progressively
        for ($i = 0; $i <= $arcSteps; $i++) {
            $t = $i / $arcSteps;
            // Quadratic bezier
            $x = (1 - $t) * (1 - $t) * $startX + 2 * (1 - $t) * $t * $controlX + $t * $t * $endX;
            $y = (1 - $t) * (1 - $t) * $startY + 2 * (1 - $t) * $t * $controlY + $t * $t * $endY;

            $row = (int) round($y);
            $col = (int) round($x);

            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                $arcPositions[] = ['row' => $row, 'col' => $col, 't' => $t];

                // Trajectory line color: blue rgb(60, 160, 255)
                $blue = 255;
                $green = (int) (160 - $t * 40);
                $red = (int) (60 + $t * 40);

                $dotChar = ($i % 3 === 0) ? '◦' : '·';
                echo Theme::moveTo($row, $col)
                    .Theme::rgb($red, $green, $blue)
                    .$dotChar.$r;
            }

            usleep(18000);
        }

        // Place waypoint markers along the arc
        $waypointIndices = [
            (int) ($arcSteps * 0.2),
            (int) ($arcSteps * 0.45),
            (int) ($arcSteps * 0.7),
            (int) ($arcSteps * 0.95),
        ];
        $waypointLabels = ['α', 'β', 'γ', 'Ω'];

        foreach ($waypointIndices as $wi => $idx) {
            if (isset($arcPositions[$idx])) {
                $wp = $arcPositions[$idx];
                $row = $wp['row'];
                $col = $wp['col'];

                // Diamond marker
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col)
                        .Theme::rgb(60, 160, 255).'◆'.$r;
                }
                // Label offset
                $labelCol = $col + 2;
                if ($row >= 1 && $row <= $this->termHeight && $labelCol >= 1 && $labelCol < $this->termWidth) {
                    echo Theme::moveTo($row, $labelCol)
                        .Theme::rgb(100, 180, 255).$waypointLabels[$wi].$r;
                }

                usleep(60000);
            }
        }

        // Title: "A U T O P I L O T" fade in cyan
        $title = 'A U T O P I L O T';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $titleRow = $this->cy;

        $cyanGradient = [
            [10, 30, 40], [20, 60, 80], [40, 100, 130], [60, 140, 180],
            [80, 180, 220], [100, 210, 245], [120, 230, 255], [140, 240, 255],
        ];

        foreach ($cyanGradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($titleRow, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(45000);
        }

        // Subtitle typeout
        $subtitle = '☄ Course plotted ☄';
        $subLen = mb_strwidth($subtitle);
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $subRow = $titleRow + 2;

        usleep(100000);

        echo Theme::moveTo($subRow, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo Theme::rgb(180, 220, 255).$char.$r;
            usleep(25000);
        }

        usleep(500000);
    }
}
