<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Unleash animation — cosmic supernova swarm release.
 *
 * A gathering vortex of energy implodes, detonates outward, and releases
 * a swarm of agent glyphs that scatter across the terminal like stars
 * being born from a cosmic explosion.
 */
class AnsiUnleash implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    private const VORTEX_CHARS = ['·', '∗', '✦', '⊛', '◆', '░', '▒'];

    private const RING_CHARS = ['░', '▒', '▓', '█', '◆', '✦'];

    private const AGENT_GLYPHS = ['⊕', '⊗', '◈', '☉', '✧', '⚡', '♅', '♆', '♄', '♃', '☿', '♁', '♂', '♀'];

    /**
     * Run the full animation sequence (vortex → detonation → swarm → title).
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
            $this->phaseGatheringStorm();
            $this->phaseDetonation();
            $this->phaseSwarmRelease();
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
     * Phase 1 — Gathering Storm.
     *
     * Particles spiral inward toward center, tightening and brightening.
     * Deep purple fading to electric blue as energy accumulates.
     */
    private function phaseGatheringStorm(): void
    {
        $r = Theme::reset();
        $totalSteps = 30;
        $particleCount = 40;

        // Generate initial particle positions (scattered across screen)
        $particles = [];
        for ($i = 0; $i < $particleCount; $i++) {
            $angle = ($i / $particleCount) * 2 * M_PI + (mt_rand(0, 100) / 100.0) * 0.5;
            $radius = mt_rand(8, max(9, min($this->cx, $this->cy) - 2));
            $particles[] = ['angle' => $angle, 'radius' => (float) $radius, 'speed' => mt_rand(80, 150) / 100.0];
        }

        for ($step = 0; $step < $totalSteps; $step++) {
            echo Theme::clearScreen();
            $progress = $step / $totalSteps;

            // Color transitions: deep purple → electric blue → cyan
            $pr = (int) (60 + 40 * (1 - $progress));
            $pg = (int) (20 + 80 * $progress);
            $pb = (int) (120 + 135 * $progress);

            foreach ($particles as &$p) {
                // Spiral inward with rotation
                $p['radius'] = max(0.5, $p['radius'] - $p['speed'] * (0.3 + 0.7 * $progress));
                $p['angle'] += 0.15 + 0.3 * $progress;

                $col = $this->cx + (int) ($p['radius'] * cos($p['angle']));
                $row = $this->cy + (int) ($p['radius'] * 0.5 * sin($p['angle']));

                if ($col >= 1 && $col <= $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    // Brightness based on proximity to center
                    $brightness = max(0.3, 1.0 - ($p['radius'] / 20.0));
                    $cr = (int) ($pr * $brightness);
                    $cg = (int) ($pg * $brightness);
                    $cb = (int) ($pb * $brightness);
                    $char = self::VORTEX_CHARS[$step % count(self::VORTEX_CHARS)];
                    echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$char.$r;
                }
            }
            unset($p);

            // Central glow intensifies
            if ($progress > 0.3) {
                $intensity = ($progress - 0.3) / 0.7;
                $gr = (int) (100 * $intensity);
                $gg = (int) (180 * $intensity);
                $gb = (int) (255 * $intensity);
                echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($gr, $gg, $gb).'⊛'.$r;
            }

            usleep(50000);
        }
    }

    /**
     * Phase 2 — Detonation.
     *
     * The accumulated energy explodes outward in concentric rings.
     * Blinding flash followed by expanding shockwaves.
     */
    private function phaseDetonation(): void
    {
        $r = Theme::reset();

        // Flash: brief white fill
        $white = Theme::rgb(255, 255, 255);
        echo Theme::clearScreen();
        for ($row = 1; $row <= $this->termHeight; $row++) {
            echo Theme::moveTo($row, 1).$white.str_repeat('█', $this->termWidth);
        }
        echo $r;
        usleep(80000);

        // Expanding rings
        $maxRadius = max($this->cx, $this->cy);
        $ringCount = 5;

        for ($wave = 0; $wave < $ringCount; $wave++) {
            echo Theme::clearScreen();
            $radius = 2 + ($wave * 3);

            // Color fades: white → cyan → purple → blue → dim
            $colors = [
                [255, 255, 255],
                [100, 220, 255],
                [140, 80, 255],
                [80, 40, 200],
                [40, 20, 120],
            ];
            [$cr, $cg, $cb] = $colors[$wave];
            $color = Theme::rgb($cr, $cg, $cb);

            // Draw ring at this radius
            $segments = max(20, (int) ($radius * 8));
            for ($i = 0; $i < $segments; $i++) {
                $angle = ($i / $segments) * 2 * M_PI;
                $col = $this->cx + (int) ($radius * cos($angle));
                $row = $this->cy + (int) ($radius * 0.5 * sin($angle));

                if ($col >= 1 && $col <= $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    $char = self::RING_CHARS[$wave % count(self::RING_CHARS)];
                    echo Theme::moveTo($row, $col).$color.$char.$r;
                }
            }

            // Core still bright
            if ($wave < 3) {
                $coreColor = Theme::rgb(255 - $wave * 50, 255 - $wave * 50, 255);
                echo Theme::moveTo($this->cy, $this->cx).$coreColor.'✦'.$r;
            }

            usleep(70000);
        }

        // Quick fade to dark
        for ($fade = 0; $fade < 3; $fade++) {
            echo Theme::clearScreen();
            $dim = max(0, 80 - $fade * 30);
            $color = Theme::rgb($dim, $dim, (int) ($dim * 1.5));
            $radius = 14 + $fade * 2;
            $segments = (int) ($radius * 6);
            for ($i = 0; $i < $segments; $i++) {
                $angle = ($i / $segments) * 2 * M_PI;
                $col = $this->cx + (int) ($radius * cos($angle));
                $row = $this->cy + (int) ($radius * 0.5 * sin($angle));
                if ($col >= 1 && $col <= $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    echo Theme::moveTo($row, $col).$color.'·'.$r;
                }
            }
            usleep(40000);
        }
    }

    /**
     * Phase 3 — Swarm Release.
     *
     * Dozens of agent glyphs erupt from the center and scatter outward
     * in all directions, leaving fading trails. The swarm is born.
     */
    private function phaseSwarmRelease(): void
    {
        $r = Theme::reset();
        $totalSteps = 35;
        $agentCount = 50;

        // Generate agent trajectories (angle + speed)
        $agents = [];
        for ($i = 0; $i < $agentCount; $i++) {
            $angle = ($i / $agentCount) * 2 * M_PI + (mt_rand(-30, 30) / 100.0);
            $speed = mt_rand(60, 140) / 100.0;
            $glyph = self::AGENT_GLYPHS[$i % count(self::AGENT_GLYPHS)];
            $agents[] = [
                'angle' => $angle,
                'speed' => $speed,
                'glyph' => $glyph,
                'delay' => mt_rand(0, 8), // staggered launch (frame offset)
            ];
        }

        /** @var array<string, array{char: string, age: int}> Trail persistence */
        $trails = [];

        for ($step = 0; $step < $totalSteps; $step++) {
            echo Theme::clearScreen();
            $progress = $step / $totalSteps;

            // Draw trails (fading)
            foreach ($trails as $key => $trail) {
                $trail['age']++;
                $trails[$key] = $trail;
                if ($trail['age'] > 4) {
                    unset($trails[$key]);

                    continue;
                }
                [$tRow, $tCol] = explode(',', $key);
                $tRow = (int) $tRow;
                $tCol = (int) $tCol;
                if ($tCol >= 1 && $tCol <= $this->termWidth && $tRow >= 1 && $tRow <= $this->termHeight) {
                    $dim = max(0, 100 - $trail['age'] * 25);
                    echo Theme::moveTo($tRow, $tCol).Theme::rgb($dim, (int) ($dim * 0.7), (int) ($dim * 1.2)).'·'.$r;
                }
            }

            // Draw agents
            foreach ($agents as $a) {
                $localStep = $step - $a['delay'];
                if ($localStep < 0) {
                    continue;
                }

                $radius = $localStep * $a['speed'] * 1.2;
                $col = $this->cx + (int) ($radius * cos($a['angle']));
                $row = $this->cy + (int) ($radius * 0.5 * sin($a['angle']));

                if ($col < 1 || $col > $this->termWidth || $row < 1 || $row > $this->termHeight) {
                    continue;
                }

                // Leave trail at current position
                $trails["{$row},{$col}"] = ['char' => '·', 'age' => 0];

                // Color: bright center → fading at edges
                $brightness = max(0.2, 1.0 - ($radius / max($this->cx, $this->cy)));
                $cr = (int) (80 + 175 * $brightness);
                $cg = (int) (150 * $brightness);
                $cb = (int) (200 + 55 * $brightness);
                echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$a['glyph'].$r;
            }

            // Center ember
            if ($step < 15) {
                $emberBright = max(0, 200 - $step * 14);
                echo Theme::moveTo($this->cy, $this->cx)
                    .Theme::rgb($emberBright, (int) ($emberBright * 0.6), $emberBright).'✦'.$r;
            }

            usleep(40000);
        }
    }

    /**
     * Phase 4 — Title reveal.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'U N L E A S H E D';
        $subtitle = '⚡ Swarm protocol initiated ⚡';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Fade in through cosmic gradient: deep purple → electric blue → cyan → white
        $gradient = [
            [30, 10, 60],
            [60, 20, 120],
            [80, 50, 180],
            [100, 100, 220],
            [120, 160, 240],
            [160, 200, 255],
            [200, 230, 255],
            [240, 250, 255],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $cyan = Theme::rgb(100, 220, 255);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $cyan.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}
