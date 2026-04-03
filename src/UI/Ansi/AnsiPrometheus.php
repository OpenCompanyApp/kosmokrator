<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Prometheus Unbound — flaming meteorite animation.
 *
 * Prometheus stole fire from Olympus. This animation shows divine fire
 * descending as a blazing meteorite that accelerates through the atmosphere,
 * impacts with a blinding flash, shatters Prometheus's chains, and unleashes
 * the titan's power.
 */
class AnsiPrometheus implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const FIRE_CHARS = ['░', '▒', '▓', '█', '◆', '✦', '⊛'];

    private const CHAIN_CHARS = ['═', '╪', '╫', '║', '╬', '━', '┃'];

    private const CORE_CHARS = ['█', '▓', '█', '▓', '█'];

    private const TRAIL_CHARS = ['░', '▒', '∙', '✦', '◆', '▪', '▓'];

    private const STREAK_CHARS = ['─', '━', '═', '—', '╌'];

    /**
     * Run the full Prometheus animation sequence (fireball → flash → chain break → title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseFireball();
        $this->phaseImpactFlash();
        $this->phaseChainBreak();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Divine fire descends from Olympus.
     *
     * Slow approach that accelerates into a screaming dive. White-hot core
     * with massive corona, atmospheric burn streaks, dense multi-column
     * flame trail, screen-edge glow, and screen shake before impact.
     */
    private function phaseFireball(): void
    {
        $r = Theme::reset();
        $totalSteps = 48;

        for ($step = 0; $step < $totalSteps; $step++) {
            $linear = $step / $totalSteps;
            // Quadratic ease-in: slow approach, dramatic acceleration
            $progress = $linear * $linear;

            // Erase ALL cells from previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Fireball descends — eased position
            $meteorY = (int) (1 + $progress * ($this->cy - 1));
            $meteorX = $this->cx;

            // Screen shake in final 10 frames
            $shakePhase = $totalSteps - 10;
            if ($step > $shakePhase) {
                $shakeMag = (int) (($step - $shakePhase) * 0.7);
                $meteorX += rand(-$shakeMag, $shakeMag);
            }

            // Intensity ramps up
            $intensity = min(1.0, 0.08 + $linear * 0.92);

            // Radii grow — much bigger in final approach
            $coreRadius = 1.0 + $progress * 3.5;
            $coronaRadius = $coreRadius + 2.0 + $progress * 5.0;

            // === Atmospheric burn streaks (re-entry heat) ===
            if ($step > 10) {
                $streakCount = min(15, (int) ($linear * 18));
                for ($s = 0; $s < $streakCount; $s++) {
                    $streakRow = rand(max(1, $meteorY - (int) ($this->cy * 0.8)), max(1, $meteorY - (int) $coreRadius - 2));
                    $streakLen = rand(3, 8 + (int) ($linear * 6));
                    $streakCol = $meteorX + rand(-4 - (int) ($linear * 3), 4 + (int) ($linear * 3));
                    for ($l = 0; $l < $streakLen; $l++) {
                        $sc = $streakCol - (int) ($streakLen / 2) + $l;
                        if ($streakRow >= 1 && $streakRow <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth) {
                            // Center of streak is brightest
                            $center = abs($l - $streakLen / 2) / ($streakLen / 2);
                            $red = max(15, (int) (220 * $intensity * (1.0 - $center * 0.7)));
                            $green = max(0, (int) (70 * $intensity * (1.0 - $center)));
                            echo Theme::moveTo($streakRow, $sc)
                                .Theme::rgb($red, $green, 0)
                                .self::STREAK_CHARS[array_rand(self::STREAK_CHARS)]
                                .$r;
                            $this->prevCells[] = ['row' => $streakRow, 'col' => $sc];
                        }
                    }
                }
            }

            // === Screen-edge glow (ambient heat) ===
            if ($linear > 0.4) {
                $glowIntensity = ($linear - 0.4) / 0.6;
                $glowCount = (int) ($glowIntensity * 25);
                for ($g = 0; $g < $glowCount; $g++) {
                    $side = rand(0, 3);
                    $gr = match ($side) {
                        0 => rand(1, 3),                           // top
                        1 => $this->termHeight - rand(0, 2),       // bottom
                        default => rand(1, $this->termHeight),     // left/right
                    };
                    $gc = match ($side) {
                        2 => rand(1, 3),                           // left
                        3 => $this->termWidth - rand(1, 3),        // right
                        default => rand(1, $this->termWidth - 1),  // top/bottom
                    };
                    if ($gr >= 1 && $gr <= $this->termHeight && $gc >= 1 && $gc < $this->termWidth) {
                        $red = (int) (120 * $glowIntensity + rand(0, 60));
                        $green = (int) (30 * $glowIntensity);
                        echo Theme::moveTo($gr, $gc)
                            .Theme::rgb(min(255, $red), $green, 0)
                            .self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                            .$r;
                        $this->prevCells[] = ['row' => $gr, 'col' => $gc];
                    }
                }
            }

            // === Corona: outer flame shell ===
            $coronaParticles = (int) (40 + $linear * 90);
            for ($p = 0; $p < $coronaParticles; $p++) {
                $angle = ($p / $coronaParticles) * 2 * M_PI;
                $jitter = (rand(0, 100) / 100.0) * 2.5;
                $span = max(0.1, $coronaRadius - $coreRadius);
                $dist = $coreRadius + 0.5 + $jitter + (rand(0, (int) ($span * 100)) / 100.0);
                $row = $meteorY + (int) round($dist * sin($angle) * 0.5);
                $col = $meteorX + (int) round($dist * cos($angle));

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    $distRatio = min(1.0, ($dist - $coreRadius) / max(0.1, $span));
                    $red = max(30, (int) (255 * $intensity * (1.0 - $distRatio * 0.55)));
                    $green = max(0, (int) (180 * $intensity * (1.0 - $distRatio * 0.85)));
                    echo Theme::moveTo($row, $col)
                        .Theme::rgb($red, $green, 0)
                        .self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                        .$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }

            // === Core: white-hot center ===
            $coreR = (int) ceil($coreRadius);
            for ($dy = -$coreR; $dy <= $coreR; $dy++) {
                for ($dx = -$coreR * 2; $dx <= $coreR * 2; $dx++) {
                    $dist = sqrt(($dx / 2.0) ** 2 + $dy ** 2);
                    if ($dist > $coreRadius) {
                        continue;
                    }
                    $row = $meteorY + $dy;
                    $col = $meteorX + $dx;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        $innerRatio = $dist / max(0.1, $coreRadius);
                        $red = (int) (255 * $intensity);
                        $green = (int) (max(140, 255 - $innerRatio * 115) * $intensity);
                        $blue = (int) (max(0, 220 - $innerRatio * 220) * $intensity);
                        echo Theme::moveTo($row, $col)
                            .Theme::rgb($red, $green, $blue)
                            .self::CORE_CHARS[array_rand(self::CORE_CHARS)]
                            .$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                }
            }

            // === Dense multi-column flame trail ===
            $trailLength = (int) (3 + $linear * 20);
            $trailColumns = max(1, (int) (1 + $linear * 5));
            for ($tc = 0; $tc < $trailColumns; $tc++) {
                $colOffset = (int) (($tc - $trailColumns / 2.0) * 2);
                for ($t = 0; $t < $trailLength; $t++) {
                    $trailRow = $meteorY - $coreR - 1 - $t;
                    $spread = max(1, (int) (1 + $t * 0.35));
                    $trailCol = $meteorX + $colOffset + rand(-$spread, $spread);

                    if ($trailRow >= 1 && $trailRow <= $this->termHeight && $trailCol >= 1 && $trailCol < $this->termWidth) {
                        $fadeRatio = $t / max(1, $trailLength);
                        $red = max(20, (int) (255 * $intensity * (1.0 - $fadeRatio * 0.7)));
                        $green = max(0, (int) (110 * $intensity * (1.0 - $fadeRatio * 0.9)));
                        echo Theme::moveTo($trailRow, $trailCol)
                            .Theme::rgb($red, $green, 0)
                            .self::TRAIL_CHARS[array_rand(self::TRAIL_CHARS)]
                            .$r;
                        $this->prevCells[] = ['row' => $trailRow, 'col' => $trailCol];
                    }
                }
            }

            // === Debris sparks flying outward ===
            if ($step > 5) {
                $debrisCount = min(14, 2 + (int) ($linear * 14));
                for ($d = 0; $d < $debrisCount; $d++) {
                    $angle = (rand(0, 360) / 360.0) * 2 * M_PI;
                    $dist = $coronaRadius + rand(1, 6 + (int) ($linear * 4));
                    $debrisRow = $meteorY + (int) round($dist * sin($angle) * 0.5);
                    $debrisCol = $meteorX + (int) round($dist * cos($angle));
                    if ($debrisRow >= 1 && $debrisRow <= $this->termHeight && $debrisCol >= 1 && $debrisCol < $this->termWidth) {
                        $heat = rand(80, 255);
                        $green = (int) ($heat * (0.3 + rand(0, 25) / 100));
                        echo Theme::moveTo($debrisRow, $debrisCol)
                            .Theme::rgb($heat, $green, 0)
                            .self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                            .$r;
                        $this->prevCells[] = ['row' => $debrisRow, 'col' => $debrisCol];
                    }
                }
            }

            // Frame timing: starts slow (60ms), accelerates to fast (30ms)
            usleep((int) (60000 - $linear * 30000));
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
     * Phase 2 — Impact flash. Blinding white explosion on contact.
     *
     * Expanding white circle fills center, brief hold, then rapid
     * fade through yellow → orange → dark red → black.
     */
    private function phaseImpactFlash(): void
    {
        $r = Theme::reset();

        // Expanding white flash
        for ($wave = 0; $wave < 4; $wave++) {
            $radius = 2 + $wave * 3;
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                for ($dx = -$radius * 2; $dx <= $radius * 2; $dx++) {
                    $dist = sqrt(($dx / 2.0) ** 2 + $dy ** 2);
                    if ($dist > $radius) {
                        continue;
                    }
                    $row = $this->cy + $dy;
                    $col = $this->cx + $dx;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        $edgeFade = min(1.0, $dist / $radius);
                        $brightness = (int) (255 * (1.0 - $edgeFade * 0.3));
                        $gb = (int) ($brightness * (1.0 - $edgeFade * 0.5));
                        echo Theme::moveTo($row, $col)
                            .Theme::rgb($brightness, $gb, max(0, $gb - 40))
                            .'█'.$r;
                    }
                }
            }
            usleep(35000);
        }

        // Hold bright
        usleep(100000);

        // Fade through fire colors to black
        $fadeSteps = [
            [255, 230, 130], [255, 180, 50], [200, 100, 10],
            [140, 45, 0], [80, 20, 0], [30, 5, 0],
        ];
        $maxRadius = 2 + 3 * 3;
        foreach ($fadeSteps as [$rv, $gv, $bv]) {
            for ($dy = -$maxRadius; $dy <= $maxRadius; $dy++) {
                for ($dx = -$maxRadius * 2; $dx <= $maxRadius * 2; $dx++) {
                    $dist = sqrt(($dx / 2.0) ** 2 + $dy ** 2);
                    if ($dist > $maxRadius) {
                        continue;
                    }
                    $row = $this->cy + $dy;
                    $col = $this->cx + $dx;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col)
                            .Theme::rgb($rv, $gv, $bv)
                            .'█'.$r;
                    }
                }
            }
            usleep(45000);
        }

        echo Theme::clearScreen();
        usleep(150000);
    }

    /**
     * Phase 3 — Chains shatter from the impact point.
     */
    private function phaseChainBreak(): void
    {
        $r = Theme::reset();

        // Draw chains scattered around center
        $chainPositions = [];
        for ($i = 0; $i < 16; $i++) {
            $row = $this->cy + rand(-5, 5);
            $col = $this->cx + rand(-18, 18);
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                $chainPositions[] = [$row, $col];
                $chainColor = Theme::rgb(100 + rand(0, 40), 80 + rand(0, 30), 60 + rand(0, 20));
                echo Theme::moveTo($row, $col).$chainColor.self::CHAIN_CHARS[array_rand(self::CHAIN_CHARS)].$r;
            }
        }
        usleep(250000);

        // Track all fire particles for cleanup
        $firePositions = [];

        // Expanding fire burst shatters chains
        for ($wave = 0; $wave < 10; $wave++) {
            $radius = $wave * 2.5;

            // Fire ring expanding outward
            $particleCount = 12 + $wave * 5;
            for ($p = 0; $p < $particleCount; $p++) {
                $angle = ($p / $particleCount) * 2 * M_PI + ($wave * 0.3);
                $jitter = (rand(0, 100) / 100.0) * 2.0;
                $dist = $radius + $jitter;
                $row = $this->cy + (int) round($dist * sin($angle) * 0.6);
                $col = $this->cx + (int) round($dist * cos($angle));

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    $heat = max(40, (int) (255 - $wave * 20 - $jitter * 15));
                    $green = max(0, (int) ($heat * 0.55) - $wave * 18);
                    echo Theme::moveTo($row, $col)
                        .Theme::rgb($heat, $green, 0)
                        .self::FIRE_CHARS[array_rand(self::FIRE_CHARS)]
                        .$r;
                    $firePositions[] = [$row, $col];
                }
            }

            // Erase chain fragments progressively
            foreach ($chainPositions as $key => [$cr, $cc]) {
                if (rand(0, 3) === 0) {
                    echo Theme::moveTo($cr, $cc).' ';
                    unset($chainPositions[$key]);
                }
            }

            usleep(55000);
        }

        usleep(200000);

        // Fade fire to embers
        for ($fade = 0; $fade < 3; $fade++) {
            foreach ($firePositions as [$fr, $fc]) {
                if (rand(0, 2) <= $fade) {
                    echo Theme::moveTo($fr, $fc).' ';
                }
            }
            usleep(80000);
        }
    }

    /**
     * Phase 4 — Title reveal through fire gradient.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'PROMETHEUS UNBOUND';
        $subtitle = '⚡ All tools auto-approved ⚡';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through fire gradient
        $fireGradient = [
            [40, 8, 0], [80, 20, 0], [140, 45, 0], [200, 80, 0],
            [240, 130, 10], [255, 180, 50], [255, 210, 90], [255, 230, 130],
        ];

        foreach ($fireGradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $gold = Theme::rgb(255, 200, 80);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $gold.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
