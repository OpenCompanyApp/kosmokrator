<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Interview — Oracle consultation animation.
 *
 * Question marks spiral inward from the edges of the screen, converging
 * on a central point of clarity. The chaotic swirl of questions collapses
 * into a single bright oracle eye, radiating golden lines of wisdom.
 */
class AnsiInterview implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const QUESTION_CHARS = ['?', '¿', '‽'];

    private const RAY_CHARS = ['─', '│', '╱', '╲'];

    /**
     * Run the full Interview animation sequence (questions → spiral → clarity → title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseQuestions();
        $this->phaseSpiral();
        $this->phaseClarity();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Questions emerge at random positions, drifting toward center (~0.8s).
     *
     * Question marks appear across the screen in purple tones, slowly
     * beginning to migrate toward the center point.
     */
    private function phaseQuestions(): void
    {
        $r = Theme::reset();

        /** @var array<int, array{row: float, col: float, char: string, brightness: int}> */
        $particles = [];

        // Spawn particles over several frames
        $totalSteps = 24;
        $spawnPerStep = 3;

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Spawn new particles at screen edges
            if ($step < $totalSteps - 4) {
                for ($s = 0; $s < $spawnPerStep; $s++) {
                    $edge = rand(0, 3);
                    $row = match ($edge) {
                        0 => (float) rand(1, 3),
                        1 => (float) ($this->termHeight - rand(0, 2)),
                        default => (float) rand(1, $this->termHeight),
                    };
                    $col = match ($edge) {
                        2 => (float) rand(1, 4),
                        3 => (float) ($this->termWidth - rand(1, 4)),
                        default => (float) rand(1, $this->termWidth - 1),
                    };
                    $brightness = rand(80, 220);
                    $particles[] = [
                        'row' => $row,
                        'col' => $col,
                        'char' => self::QUESTION_CHARS[array_rand(self::QUESTION_CHARS)],
                        'brightness' => $brightness,
                    ];
                }
            }

            // Drift all particles toward center
            $driftStrength = 0.03 + $progress * 0.06;
            foreach ($particles as &$p) {
                $dy = ($this->cy - $p['row']) * $driftStrength;
                $dx = ($this->cx - $p['col']) * $driftStrength;
                $p['row'] += $dy + (rand(-10, 10) / 30.0);
                $p['col'] += $dx + (rand(-10, 10) / 20.0);
            }
            unset($p);

            // Render particles
            foreach ($particles as $p) {
                $row = (int) round($p['row']);
                $col = (int) round($p['col']);
                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    $b = $p['brightness'];
                    // Purple tones: scale RGB around purple base
                    $rv = (int) min(255, 100 + $b * 0.35);
                    $gv = (int) min(255, 30 + $b * 0.15);
                    $bv = (int) min(255, 140 + $b * 0.52);
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $gv, $bv).$p['char'].$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }

            usleep(33000);
        }

        // Store particles for next phase
        $this->spiralParticles = $particles;
    }

    /** @var array<int, array{row: float, col: float, char: string, brightness: int}> Particles carried between phases */
    private array $spiralParticles = [];

    /**
     * Phase 2 — Questions accelerate into a spiral converging on center (~0.8s).
     *
     * Colors transition from purple to white as particles approach the center.
     * Trailing effect follows each question mark.
     */
    private function phaseSpiral(): void
    {
        $r = Theme::reset();
        $particles = $this->spiralParticles;
        $totalSteps = 28;

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;
            // Ease-in: accelerate toward center
            $speed = 0.06 + $progress * $progress * 0.22;

            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            foreach ($particles as &$p) {
                $dy = $this->cy - $p['row'];
                $dx = $this->cx - $p['col'];
                $dist = sqrt($dy * $dy + ($dx * 0.5) * ($dx * 0.5));

                // Spiral: add tangential velocity perpendicular to radial
                $angle = atan2($dy, $dx * 0.5);
                $tangentAngle = $angle + M_PI / 2;
                $spiralStrength = max(0, 1.8 - $progress * 2.0) * min(1.0, $dist / 10.0);

                $p['row'] += $dy * $speed + sin($tangentAngle) * $spiralStrength * 0.5;
                $p['col'] += $dx * $speed + cos($tangentAngle) * $spiralStrength * 1.2;

                // Increase brightness as approaching center (purple → white)
                $centerProximity = max(0, 1.0 - $dist / max(1, (int) ($this->termHeight * 0.5)));
                $p['brightness'] = min(255, $p['brightness'] + (int) ($centerProximity * 8));
            }
            unset($p);

            // Render particles with trails
            foreach ($particles as $p) {
                $row = (int) round($p['row']);
                $col = (int) round($p['col']);

                // Trail: dim echo behind the particle
                $trailRow = $row + (int) (($row - $this->cy) * 0.15);
                $trailCol = $col + (int) (($col - $this->cx) * 0.15);
                if ($trailRow >= 1 && $trailRow <= $this->termHeight && $trailCol >= 1 && $trailCol < $this->termWidth) {
                    echo Theme::moveTo($trailRow, $trailCol).Theme::rgb(80, 40, 120).'·'.$r;
                    $this->prevCells[] = ['row' => $trailRow, 'col' => $trailCol];
                }

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    $b = $p['brightness'];
                    // Transition purple → white based on brightness
                    $whiteness = max(0.0, ($b - 140) / 115.0);
                    $rv = (int) min(255, 100 + $b * 0.35 + $whiteness * 80);
                    $gv = (int) min(255, 30 + $b * 0.15 + $whiteness * 160);
                    $bv = (int) min(255, 140 + $b * 0.52 + $whiteness * 40);
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $gv, $bv).$p['char'].$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }

            usleep(28000);
        }
    }

    /**
     * Phase 3 — All questions collapse into center, oracle eye appears (~0.7s).
     *
     * Brief white flash at the convergence point, then a bright oracle symbol
     * radiates golden lines outward in eight directions.
     */
    private function phaseClarity(): void
    {
        $r = Theme::reset();

        // Final collapse: erase everything
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        // White flash expanding from center
        for ($wave = 0; $wave < 3; $wave++) {
            $radius = 1 + $wave * 2;
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                for ($dx = -$radius * 2; $dx <= $radius * 2; $dx++) {
                    $dist = sqrt(($dx / 2.0) ** 2 + $dy ** 2);
                    if ($dist > $radius) {
                        continue;
                    }
                    $row = $this->cy + $dy;
                    $col = $this->cx + $dx;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        $brightness = (int) (255 * (1.0 - $dist / max(1, $radius) * 0.4));
                        echo Theme::moveTo($row, $col)
                            .Theme::rgb($brightness, $brightness, min(255, $brightness + 10))
                            .'█'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                }
            }
            usleep(40000);
        }

        usleep(80000);

        // Fade flash
        $maxRadius = 1 + 2 * 2;
        for ($fade = 0; $fade < 3; $fade++) {
            for ($dy = -$maxRadius; $dy <= $maxRadius; $dy++) {
                for ($dx = -$maxRadius * 2; $dx <= $maxRadius * 2; $dx++) {
                    $dist = sqrt(($dx / 2.0) ** 2 + $dy ** 2);
                    if ($dist > $maxRadius) {
                        continue;
                    }
                    $row = $this->cy + $dy;
                    $col = $this->cx + $dx;
                    if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                        $brightness = max(0, (int) (180 - $fade * 70 - $dist * 15));
                        if ($brightness < 10) {
                            echo Theme::moveTo($row, $col).' ';
                        } else {
                            echo Theme::moveTo($row, $col)
                                .Theme::rgb($brightness, $brightness, min(255, $brightness + 10))
                                .'░'.$r;
                        }
                    }
                }
            }
            usleep(40000);
        }

        // Clear flash area
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        // Oracle eye at center
        if ($this->cy >= 1 && $this->cy <= $this->termHeight && $this->cx >= 1 && $this->cx < $this->termWidth) {
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb(240, 240, 255).'◉'.$r;
            $this->prevCells[] = ['row' => $this->cy, 'col' => $this->cx];
        }
        usleep(60000);

        // Radiating lines in 8 directions (amber/gold)
        $directions = [
            [0, 1, '─'], [0, -1, '─'],
            [1, 0, '│'], [-1, 0, '│'],
            [1, 1, '╲'], [-1, -1, '╲'],
            [1, -1, '╱'], [-1, 1, '╱'],
        ];

        $maxLen = min((int) ($this->termWidth * 0.35), (int) ($this->termHeight * 0.6));

        for ($len = 1; $len <= $maxLen; $len++) {
            foreach ($directions as [$dRow, $dCol, $char]) {
                // Horizontal directions need 2x column step for aspect ratio
                $colStep = $dCol * ($dRow === 0 ? 1 : 2);
                $row = $this->cy + $dRow * $len;
                $col = $this->cx + $colStep * $len;

                if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth) {
                    // Gold fading outward
                    $fade = max(0.15, 1.0 - ($len / (float) $maxLen) * 0.85);
                    $rv = (int) (255 * $fade);
                    $gv = (int) (200 * $fade);
                    $bv = (int) (80 * $fade);
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $gv, $bv).$char.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }
            usleep(15000);
        }

        // Re-draw oracle eye on top (in case rays overwrote it)
        if ($this->cy >= 1 && $this->cy <= $this->termHeight && $this->cx >= 1 && $this->cx < $this->termWidth) {
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb(240, 240, 255).'◉'.$r;
        }

        usleep(300000);
    }

    /**
     * Phase 4 — Title reveal with oracle glow (~0.7s).
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'I N T E R V I E W';
        $subtitle = '◉ Clarity achieved ◉';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through purple → white gradient
        $clarityGradient = [
            [60, 20, 100], [90, 35, 150], [120, 50, 200], [150, 70, 230],
            [180, 120, 240], [200, 170, 245], [220, 210, 250], [240, 240, 255],
        ];

        foreach ($clarityGradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(50000);
        }

        // Subtitle typeout
        usleep(120000);
        $amber = Theme::rgb(255, 200, 80);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $amber.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
