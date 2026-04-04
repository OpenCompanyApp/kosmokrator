<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * The Theogony — KosmoKrator's origin spectacle.
 *
 * A mythological epic told in ANSI, inspired by Hesiod's Theogonia.
 * Eight chapters tracing the birth of the cosmos from primordial Chaos
 * to the enthronement of the Ruler.
 */
class AnsiTheogony implements AnsiAnimation
{
    use AnimationSignalHandler;

    private $stdinStream = null;

    private ?string $originalTtyMode = null;

    private int $termWidth = 120;

    private int $termHeight = 30;

    private int $cx;

    private int $cy;

    private const LOGO_LINES = [
        '██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗ ',
        '██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗',
        '█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝ ',
        '██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗ ',
        '██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║',
        '╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝',
    ];

    private const LOGO_GRADIENTS = [
        [180, 20, 20], [220, 40, 30], [255, 60, 40],
        [255, 80, 50], [220, 40, 30], [160, 20, 20],
    ];

    private const CHAOS_CHARS = ['·', '∙', '✧', '⋆', '˙', '✦', '⊹', '°', '∘'];

    private const RAIN_CHARS = [
        'α', 'β', 'γ', 'δ', 'ε', 'ζ', 'η', 'θ', 'ι', 'κ', 'λ', 'μ',
        'ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ',
        '☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆',
        '✧', '⊛', '◈', '⋆', '∘', '·',
    ];

    private const SCRAMBLE_CHARS = [
        '█', '▓', '▒', '░', '◈', '⊛', '✧', '⋆', '∘', '·',
        '╬', '╫', '╪', '║', '═', '╗', '╔', '╝', '╚',
        'Ω', 'Σ', 'Δ', 'Π', 'Θ', 'Ψ', 'Φ', 'Λ',
    ];

    private const WYRM = [
        '          /\\    /\\',
        '         /  \\‾‾/  \\',
        '        | ◉      ◉ |',
        '         \\  ·▽·  /',
        '     /\\   \\────/   /\\',
        '    /  \\≈≈≈|    |≈≈≈/  \\',
        '   / ≈  \\  |    |  / ≈  \\',
        '  /≈ ∿∿ ≈\\ |    | /≈ ∿∿ ≈\\',
        '  \\≈ ∿∿ ≈/ |    | \\≈ ∿∿ ≈/',
        '   \\ ≈  /  |    |  \\ ≈  /',
        '    \\  /≈≈≈|    |≈≈≈\\  /',
        '     \\/   /────\\   \\/',
        '          | ⊛⊛ |',
        '          |    |',
        '         /|    |\\',
        '        / |    | \\',
        '       /__|    |__\\',
    ];

    /**
     * Run the full eight-chapter Theogony animation sequence.
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

        $this->enableNonBlockingInput();

        try {
            $this->installSignalHandler();

            $this->titleCard('Ι', 'ΧΑΟΣ', 'In the beginning, there was nothing.');
            $this->phaseChaos();

            $this->fadeToBlack();
            $this->titleCard('ΙΙ', 'ΕΡΕΒΟΣ ΚΑΙ ΝΥΞ', 'From the void came Darkness and Night.');
            $this->phaseErebosAndNyx();

            $this->fadeToBlack();
            $this->titleCard('ΙΙΙ', 'ΦΩΣ', 'And then — Light.');
            $this->phaseSpark();

            $this->fadeToBlack();
            $this->titleCard('ΙV', 'ΓΑΙΑ', 'The Earth took shape beneath the stars.');
            $this->phaseGaia();

            $this->fadeToBlack();
            $this->titleCard('V', 'ΤΙΤΑΝΕΣ', 'The ancient powers awakened.');
            $this->phaseTitans();

            $this->fadeToBlack();
            $this->titleCard('VΙ', 'ΤΙΤΑΝΟΜΑΧΙΑ', 'War shook the heavens.');
            $this->phaseTitanomachy();

            $this->fadeToBlack();
            $this->titleCard('VΙΙ', 'ΚΟΣΜΟΚΡΑΤΩΡ', 'From the ashes, a Ruler was forged.');
            $this->phaseCosmicRain();
            $this->phaseLogoIgnite();

            if ($this->termHeight > 30) {
                $this->titleCard('VΙΙΙ', 'Η ΤΑΞΗ', 'The cosmos took its eternal shape.');
                $this->phaseCosmicAssembly();
            }

            $this->phaseEpilogue();

            echo Theme::moveTo($this->termHeight, 1);
            echo Theme::showCursor();
        } catch (IntroSkippedException) {
            echo Theme::clearScreen();
        } finally {
            $this->restoreInput();
            $this->restoreSignalHandler();
            TerminalSize::reset();
        }
    }

    // ──────────────────────────────────────────────────────
    // Title cards & transitions (unchanged)
    // ──────────────────────────────────────────────────────

    /** Display a chapter title card with fade-in/out and typewriter subtitle. */
    private function titleCard(string $numeral, string $greek, string $subtitle): void
    {
        $r = Theme::reset();
        $header = $numeral.'.  '.$greek;
        $headerLen = mb_strwidth($header);
        $subtitleLen = mb_strwidth($subtitle);
        $headerCol = max(1, (int) (($this->termWidth - $headerLen) / 2));
        $subtitleCol = max(1, (int) (($this->termWidth - $subtitleLen) / 2));
        $titleRow = $this->cy - 1;
        $subRow = $this->cy + 1;
        $lineWidth = max($headerLen, $subtitleLen) + 8;
        $lineCol = max(1, (int) (($this->termWidth - $lineWidth) / 2));

        $fadeSteps = [[30, 20, 20], [60, 30, 25], [100, 40, 30], [160, 50, 35], [220, 60, 40], [255, 60, 40]];
        foreach ($fadeSteps as [$rv, $g, $b]) {
            echo Theme::moveTo($titleRow, $headerCol).Theme::rgb($rv, $g, $b).$header.$r;
            usleep(60000);
        }

        usleep(100000);
        $lineColor = Theme::rgb(80, 30, 25);
        echo Theme::moveTo($titleRow - 1, $lineCol);
        for ($i = 0; $i < $lineWidth; $i++) {
            echo $lineColor.'─'.$r;
            usleep(2000);
        }
        echo Theme::moveTo($titleRow + 1, $lineCol);
        for ($i = 0; $i < $lineWidth; $i++) {
            echo $lineColor.'─'.$r;
            usleep(2000);
        }

        usleep(200000);
        echo Theme::moveTo($subRow + 1, $subtitleCol);
        $dim = Theme::rgb(140, 130, 120);
        foreach (mb_str_split($subtitle) as $char) {
            echo $dim.$char.$r;
            usleep(30000);
        }

        usleep(1200000);

        for ($v = 140; $v >= 0; $v -= 20) {
            $v = max(0, $v);
            $color = Theme::rgb($v, (int) ($v * 0.4), (int) ($v * 0.3));
            $dimColor = Theme::rgb((int) ($v * 0.8), (int) ($v * 0.7), (int) ($v * 0.6));
            echo Theme::moveTo($titleRow, $headerCol).$color.$header.$r;
            echo Theme::moveTo($subRow + 1, $subtitleCol).$dimColor.$subtitle.$r;
            usleep(40000);
        }

        usleep(200000);
        echo Theme::clearScreen();
    }

    /** Brief pause and screen clear between chapters. */
    private function fadeToBlack(): void
    {
        usleep(300000);
        echo Theme::clearScreen();
        usleep(400000);
    }

    // ──────────────────────────────────────────────────────
    // I. ΧΑΟΣ — Chaos (~5 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseChaos(): void
    {
        $r = Theme::reset();

        // Drifting particles with velocity
        $particles = [];
        $maxParticles = (int) ($this->termWidth * $this->termHeight * 0.012);
        $maxParticles = max(30, min($maxParticles, 100));

        for ($i = 0; $i < $maxParticles; $i++) {
            $particles[] = $this->spawnChaosParticle();
        }

        $frames = 220;
        $centerPulsePhase = 0;
        $vortexChars = ['·', '∘', '○', '◌', '○', '∘'];

        for ($frame = 0; $frame < $frames; $frame++) {
            $progress = $frame / $frames;
            $this->checkSkip();

            // Update and render drifting particles
            foreach ($particles as &$p) {
                // Erase old position
                if ($p['life'] < $p['maxLife']) {
                    echo Theme::moveTo((int) $p['row'], (int) $p['col']).' ';
                }

                if ($p['life'] <= 0) {
                    $p = $this->spawnChaosParticle();

                    continue;
                }

                // Move by velocity
                $p['row'] += $p['vRow'];
                $p['col'] += $p['vCol'];

                // Wrap around edges
                if ($p['row'] < 1) {
                    $p['row'] = $this->termHeight;
                }
                if ($p['row'] > $this->termHeight) {
                    $p['row'] = 1;
                }
                if ($p['col'] < 1) {
                    $p['col'] = $this->termWidth - 1;
                }
                if ($p['col'] >= $this->termWidth) {
                    $p['col'] = 1;
                }

                // Color: starts gray, shifts toward purple as Chaos deepens
                $brightness = (int) (30 + ($p['life'] / $p['maxLife']) * 60);
                $purpleShift = (int) ($progress * 30);
                $color = Theme::rgb($brightness + $purpleShift, $brightness, $brightness + $purpleShift + rand(0, 15));
                echo Theme::moveTo((int) $p['row'], (int) $p['col']).$color.$p['char'].$r;
                $p['life']--;
            }
            unset($p);

            // Void tendrils — creep outward from center (every 30 frames, spawn a new one)
            if ($frame > 40 && $frame % 30 === 0 && $frame < 180) {
                $tendrilLen = rand(8, 20);
                $angle = rand(0, 359);
                $this->drawTendril($this->cy, $this->cx, $tendrilLen, $angle, Theme::rgb(25, 15, 30));
            }

            // Swirling vortex at center — speeds up over time
            $vortexSpeed = 0.08 + $progress * 0.25;
            $centerPulsePhase += $vortexSpeed;
            $vortexRadius = 2 + (int) ($progress * 3);

            for ($v = 0; $v < 6; $v++) {
                $vAngle = $centerPulsePhase + ($v * M_PI / 3);
                $vCol = $this->cx + (int) round($vortexRadius * cos($vAngle) * 2);
                $vRow = $this->cy - (int) round($vortexRadius * sin($vAngle));
                if ($this->inBounds($vRow, $vCol)) {
                    $vBright = (int) (50 + sin($centerPulsePhase * 2) * 30 + $progress * 60);
                    echo Theme::moveTo($vRow, $vCol).Theme::rgb($vBright, $vBright, $vBright + 20).$vortexChars[$v].$r;
                }
            }

            // Center point grows
            $pulse = (int) (50 + sin($centerPulsePhase * 1.5) * 40 + $progress * 60);
            $centerChar = $frame < 60 ? '·' : ($frame < 140 ? '∘' : '✦');
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($pulse, $pulse, $pulse + 20).$centerChar.$r;

            usleep(18000);
        }

        // Fade out particles
        foreach ($particles as $p) {
            echo Theme::moveTo((int) $p['row'], (int) $p['col']).' ';
        }
    }

    // ──────────────────────────────────────────────────────
    // II. ΕΡΕΒΟΣ ΚΑΙ ΝΥΞ — Darkness and Night (~8 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseErebosAndNyx(): void
    {
        $r = Theme::reset();

        // Phase 1: Erebos — shadow tendrils creep from corners
        $corners = [[1, 1], [1, $this->termWidth - 1], [$this->termHeight, 1], [$this->termHeight, $this->termWidth - 1]];
        for ($wave = 0; $wave < 5; $wave++) {
            foreach ($corners as [$cRow, $cCol]) {
                $tendrilLen = rand(10, 25);
                $angleToCenter = (int) (atan2($this->cy - $cRow, $this->cx - $cCol) * 180 / M_PI);
                $angle = $angleToCenter + rand(-40, 40);
                $brightness = max(8, 30 - $wave * 4);
                $this->drawTendril($cRow, $cCol, $tendrilLen, $angle, Theme::rgb($brightness, $brightness, (int) ($brightness * 0.7)));
            }
            usleep(150000);
        }

        // Darkness creeping inward (edge waves, faster)
        for ($wave = 0; $wave < 6; $wave++) {
            $depth = $wave + 1;
            $brightness = max(8, 35 - $wave * 5);
            $color = Theme::rgb($brightness, $brightness, (int) ($brightness * 0.7));
            $char = ['░', '▒', '▓', '█', '▓', '▒'][$wave % 6];

            for ($col = 1; $col < $this->termWidth; $col += 3) {
                if ($this->inBounds($depth, $col)) {
                    echo Theme::moveTo($depth, $col).$color.$char.$r;
                }
                $botRow = $this->termHeight - $depth + 1;
                if ($this->inBounds($botRow, $col)) {
                    echo Theme::moveTo($botRow, $col).$color.$char.$r;
                }
            }
            for ($row = $depth; $row <= $this->termHeight - $depth + 1; $row += 2) {
                if ($this->inBounds($row, $depth)) {
                    echo Theme::moveTo($row, $depth).$color.$char.$r;
                }
                $rightCol = $this->termWidth - $depth;
                if ($this->inBounds($row, $rightCol)) {
                    echo Theme::moveTo($row, $rightCol).$color.$char.$r;
                }
            }
            usleep(80000);
        }

        // Phase 2: Nyx's veil sweeps left to right
        usleep(300000);
        $sweepWidth = 6;
        for ($sweepCol = 1; $sweepCol <= $this->termWidth + $sweepWidth; $sweepCol += 3) {
            for ($row = 1; $row <= $this->termHeight; $row += 2) {
                for ($w = 0; $w < $sweepWidth; $w++) {
                    $col = $sweepCol - $w;
                    if ($this->inBounds($row, $col)) {
                        $depth = $w / $sweepWidth;
                        $rv = (int) (10 + $depth * 15);
                        $g = (int) (5 + $depth * 10);
                        $b = (int) (30 + (1 - $depth) * 40);
                        echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).'░'.$r;
                    }
                }
            }
            usleep(5000);
        }

        // Phase 3: Night stars emerge with positions tracked for twinkling
        $starPositions = [];
        $nightStars = (int) ($this->termWidth * $this->termHeight * 0.02);
        $nightStars = max(40, min($nightStars, 120));

        for ($i = 0; $i < $nightStars; $i++) {
            $row = rand(1, $this->termHeight);
            $col = rand(1, $this->termWidth - 1);
            $char = self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)];
            $rv = rand(20, 60);
            $g = rand(15, 40);
            $b = rand(60, 140);
            echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
            $starPositions[] = ['row' => $row, 'col' => $col, 'char' => $char, 'r' => $rv, 'g' => $g, 'b' => $b];
            usleep(5000);
        }

        // Phase 4: Stars twinkle
        for ($twinkle = 0; $twinkle < 80; $twinkle++) {
            $this->checkSkip();
            $idx = rand(0, count($starPositions) - 1);
            $s = $starPositions[$idx];
            $bright = rand(0, 1);
            $mult = $bright ? 1.8 : 0.5;
            $rv = min(255, (int) ($s['r'] * $mult));
            $g = min(255, (int) ($s['g'] * $mult));
            $b = min(255, (int) ($s['b'] * $mult));
            echo Theme::moveTo($s['row'], $s['col']).Theme::rgb($rv, $g, $b).$s['char'].$r;
            usleep(25000);
        }

        // Phase 5: Constellations — connect nearby star clusters
        $constellationColor = Theme::rgb(25, 20, 50);
        for ($c = 0; $c < min(4, (int) (count($starPositions) / 8)); $c++) {
            $baseIdx = $c * (int) (count($starPositions) / 4);
            for ($j = 0; $j < 3; $j++) {
                $s1 = $starPositions[$baseIdx + $j] ?? null;
                $s2 = $starPositions[$baseIdx + $j + 1] ?? null;
                if ($s1 && $s2) {
                    $this->drawLine($s1['row'], $s1['col'], $s2['row'], $s2['col'], $constellationColor, '·');
                    usleep(30000);
                }
            }
        }

        // Moon with halo
        $moonRow = (int) ($this->termHeight * 0.25);
        $moonCol = (int) ($this->termWidth * 0.72);
        // Halo ring
        for ($angle = 0; $angle < 360; $angle += 30) {
            $rad = deg2rad($angle);
            $hCol = $moonCol + (int) round(3 * cos($rad) * 2);
            $hRow = $moonRow - (int) round(2 * sin($rad));
            if ($this->inBounds($hRow, $hCol)) {
                echo Theme::moveTo($hRow, $hCol).Theme::rgb(40, 40, 70).'·'.$r;
            }
        }
        usleep(100000);
        // Moon fade-in
        foreach ([[30, 30, 50], [60, 60, 100], [120, 120, 170], [180, 180, 220]] as [$rv, $g, $b]) {
            echo Theme::moveTo($moonRow, $moonCol).Theme::rgb($rv, $g, $b).'☽'.$r;
            usleep(80000);
        }

        // Owl crossing
        $owlFrames = ['◖◗', '◖◗', '◗◖'];
        $owlRow = (int) ($this->termHeight * 0.15);
        for ($oCol = 3; $oCol < $this->termWidth - 5; $oCol += 3) {
            if ($oCol > 3) {
                echo Theme::moveTo($owlRow, $oCol - 3).'  ';
            }
            $owlChar = $owlFrames[$oCol % count($owlFrames)];
            echo Theme::moveTo($owlRow, $oCol).Theme::rgb(60, 50, 80).$owlChar.$r;
            usleep(15000);
        }
        echo Theme::moveTo($owlRow, $this->termWidth - 5).'  ';

        // Narration
        $this->typeNarration('Erebos shrouded the abyss. Nyx veiled the heavens.', Theme::rgb(70, 60, 90));
        usleep(600000);
    }

    // ──────────────────────────────────────────────────────
    // III. ΦΩΣ — Light / The Spark (~7 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseSpark(): void
    {
        $r = Theme::reset();

        // Big flash at center — expanding bright chars
        $flashChars = ['·', '✦', '✴', '✳', '❋', '✺', '☀'];
        foreach ($flashChars as $j => $char) {
            $brightness = 255 - $j * 20;
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($brightness, $brightness, min(255, $brightness + 20)).$char.$r;
            // Flash also radiates to nearby cells
            for ($d = 1; $d <= min(3, $j + 1); $d++) {
                $dirs = [[-1, 0], [1, 0], [0, -2], [0, 2], [-1, -2], [-1, 2], [1, -2], [1, 2]];
                foreach ($dirs as [$dr, $dc]) {
                    $fRow = $this->cy + $dr * $d;
                    $fCol = $this->cx + $dc * $d;
                    if ($this->inBounds($fRow, $fCol)) {
                        $fb = max(80, $brightness - $d * 40);
                        echo Theme::moveTo($fRow, $fCol).Theme::rgb($fb, $fb, min(255, $fb + 30)).'·'.$r;
                    }
                }
            }
            usleep(35000);
        }

        // 3 shockwaves at different speeds
        $maxRadius = min((int) ($this->termWidth / 4), (int) ($this->termHeight / 2)) - 2;
        $maxRadius = max(5, min($maxRadius, 18));

        // Launch shockwaves as interleaved rings
        $waves = [
            ['delay' => 0, 'charSet' => ['○', '◌', '○'], 'speed' => 1],
            ['delay' => 3, 'charSet' => ['∘', '·', '∘'], 'speed' => 1],
            ['delay' => 6, 'charSet' => ['·', '˙', '·'], 'speed' => 1],
        ];

        for ($radius = 1; $radius <= $maxRadius + 6; $radius++) {
            foreach ($waves as $wIdx => $wave) {
                $wRadius = $radius - $wave['delay'];
                if ($wRadius < 1 || $wRadius > $maxRadius) {
                    continue;
                }

                $ringChar = $wave['charSet'][$wRadius % count($wave['charSet'])];
                $brightness = max(60, 255 - ($wRadius * 12) - ($wIdx * 30));
                $warmth = $wIdx * 20;
                $this->drawRing($this->cy, $this->cx, $wRadius, $ringChar, [$brightness, max(0, $brightness - $warmth), max(0, $brightness - $warmth * 2)]);

                // Fade previous ring
                if ($wRadius > 2) {
                    $this->drawRing($this->cy, $this->cx, $wRadius - 2, '·', [30, 25, 35]);
                }
                // Erase old ring
                if ($wRadius > 4) {
                    $this->drawRing($this->cy, $this->cx, $wRadius - 4, ' ', [0, 0, 0], true);
                }
            }
            usleep(15000);
        }

        // Clean remaining rings
        for ($radius = $maxRadius - 3; $radius <= $maxRadius; $radius++) {
            if ($radius > 0) {
                $this->drawRing($this->cy, $this->cx, $radius, ' ', [0, 0, 0], true);
            }
        }

        // Scattered blast particles with physics
        $blastParticles = [];
        for ($i = 0; $i < 50; $i++) {
            $angle = rand(0, 359);
            $speed = 0.3 + rand(0, 100) / 100.0;
            $blastParticles[] = [
                'row' => (float) $this->cy,
                'col' => (float) $this->cx,
                'vRow' => -sin(deg2rad($angle)) * $speed,
                'vCol' => cos(deg2rad($angle)) * $speed * 2,
                'life' => rand(15, 40),
                'char' => self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)],
            ];
        }

        for ($frame = 0; $frame < 50; $frame++) {
            $this->checkSkip();
            foreach ($blastParticles as &$p) {
                if ($p['life'] <= 0) {
                    continue;
                }
                // Erase old
                $oldRow = (int) ($p['row'] - $p['vRow']);
                $oldCol = (int) ($p['col'] - $p['vCol']);
                if ($this->inBounds($oldRow, $oldCol)) {
                    echo Theme::moveTo($oldRow, $oldCol).' ';
                }
                // Draw new
                $row = (int) $p['row'];
                $col = (int) $p['col'];
                if ($this->inBounds($row, $col)) {
                    $brightness = (int) (80 + ($p['life'] / 40.0) * 175);
                    $warmth = (int) (($p['life'] / 40.0) * 200);
                    echo Theme::moveTo($row, $col).Theme::rgb(min(255, $brightness), min(255, $warmth), 0).$p['char'].$r;
                }
                $p['row'] += $p['vRow'];
                $p['col'] += $p['vCol'];
                $p['vRow'] *= 0.96; // Friction
                $p['vCol'] *= 0.96;
                $p['life']--;
            }
            unset($p);
            usleep(20000);
        }

        // Lightning storm — 5 bolts from various positions
        usleep(100000);
        $boltStarts = [
            [$this->cy - (int) ($this->termHeight * 0.4), $this->cx - 20],
            [$this->cy - (int) ($this->termHeight * 0.4), $this->cx + 18],
            [2, (int) ($this->termWidth * 0.2)],
            [2, (int) ($this->termWidth * 0.8)],
            [$this->cy - (int) ($this->termHeight * 0.35), $this->cx],
        ];
        foreach ($boltStarts as [$bRow, $bCol]) {
            $this->drawLightningBolt($bRow, $bCol);
            usleep(60000);
        }

        // Nebula glow — warm fog patches at center
        usleep(200000);
        $nebulaChars = ['░', '▒', '·'];
        for ($i = 0; $i < 40; $i++) {
            $nRow = $this->cy + rand(-4, 4);
            $nCol = $this->cx + rand(-10, 10);
            if ($this->inBounds($nRow, $nCol)) {
                $rv = rand(60, 120);
                $g = rand(40, 80);
                $b = rand(20, 50);
                echo Theme::moveTo($nRow, $nCol).Theme::rgb($rv, $g, $b).$nebulaChars[array_rand($nebulaChars)].$r;
            }
            usleep(8000);
        }

        // Starfield settles around the nebula
        $this->drawStarfield(70);

        // Afterglow embers floating upward
        $this->animateEmbers(25, 60, 20000);

        // Narration
        $this->typeNarration('From the clash of Darkness and Void, Light was born.', Theme::rgb(160, 140, 80));
        usleep(600000);
    }

    // ──────────────────────────────────────────────────────
    // IV. ΓΑΙΑ — The Earth (~8 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseGaia(): void
    {
        $r = Theme::reset();
        $groundRow = (int) ($this->termHeight * 0.65);

        // Stars above
        $this->drawStarfield(50);

        // Sunrise glow — warm gradient rising behind the horizon
        usleep(200000);
        for ($glowRow = $groundRow - 1; $glowRow >= max(1, $groundRow - 8); $glowRow--) {
            $dist = $groundRow - 1 - $glowRow;
            $rv = max(20, 180 - $dist * 20);
            $g = max(10, 100 - $dist * 12);
            $b = max(5, 40 - $dist * 5);
            for ($col = (int) ($this->termWidth * 0.2); $col < (int) ($this->termWidth * 0.8); $col += 2) {
                if ($this->inBounds($glowRow, $col)) {
                    $jitter = rand(-15, 15);
                    echo Theme::moveTo($glowRow, $col).Theme::rgb(max(0, $rv + $jitter), max(0, $g + $jitter), max(0, $b)).'░'.$r;
                }
            }
            usleep(40000);
        }

        // Earth rises from center outward
        usleep(200000);
        $halfWidth = (int) ($this->termWidth / 2);
        for ($spread = 0; $spread <= $halfWidth; $spread += 2) {
            $leftCol = max(1, $this->cx - $spread);
            $rightCol = min($this->termWidth - 1, $this->cx + $spread);

            for ($col = $leftCol; $col <= $rightCol; $col++) {
                $normalizedCol = ($col - $this->cx) / (float) max(1, $halfWidth);
                $terrainHeight = (int) (3 + 2 * sin($normalizedCol * 3.0) + 1.5 * cos($normalizedCol * 7.0));
                $terrainHeight = max(1, min(6, $terrainHeight));

                for ($h = 0; $h < $terrainHeight; $h++) {
                    $row = $groundRow + $h;
                    if (! $this->inBounds($row, $col)) {
                        continue;
                    }
                    if ($h === 0) {
                        $rv = 40 + rand(0, 20);
                        $g = 100 + rand(0, 60);
                        $b = 30 + rand(0, 15);
                    } else {
                        $depth = $h / max(1, $terrainHeight - 1);
                        $rv = (int) (100 + $depth * 60);
                        $g = (int) (50 + (1 - $depth) * 30);
                        $b = (int) (20 + $depth * 20);
                    }
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).'█'.$r;
                }
            }
            usleep(8000);
        }

        // Mountains
        $mountains = [
            [(int) ($this->termWidth * 0.2), 4],
            [(int) ($this->termWidth * 0.4), 6],
            [(int) ($this->termWidth * 0.55), 8],
            [(int) ($this->termWidth * 0.75), 5],
            [(int) ($this->termWidth * 0.9), 3],
        ];
        foreach ($mountains as [$peakCol, $height]) {
            for ($h = 0; $h < $height; $h++) {
                $row = $groundRow - $h - 1;
                $width = ($height - $h) * 2;
                for ($w = -$width; $w <= $width; $w++) {
                    $col = $peakCol + $w;
                    if (! $this->inBounds($row, $col)) {
                        continue;
                    }
                    $shade = max(40, 100 - $h * 10);
                    $snowCap = ($h >= $height - 2 && $height >= 5);
                    if ($snowCap) {
                        echo Theme::moveTo($row, $col).Theme::rgb(200, 200, 210).($h === $height - 1 ? '▲' : '█').$r;
                    } else {
                        echo Theme::moveTo($row, $col).Theme::rgb($shade, $shade + 10, $shade - 10).'█'.$r;
                    }
                }
            }
            usleep(60000);
        }

        // Animated ocean — bottom 2 rows
        $oceanTop = $groundRow + 6;
        $waveChars = ['~', '≈', '∿', '~', '≈'];
        for ($waveFrame = 0; $waveFrame < 40; $waveFrame++) {
            for ($row = $oceanTop; $row <= $this->termHeight; $row++) {
                for ($col = 1; $col < $this->termWidth; $col += 3) {
                    if (! $this->inBounds($row, $col)) {
                        continue;
                    }
                    $char = $waveChars[($col + $waveFrame) % count($waveChars)];
                    $depth = ($row - $oceanTop) / max(1, $this->termHeight - $oceanTop);
                    $rv = (int) (20 + $depth * 10);
                    $g = (int) (60 + (1 - $depth) * 40);
                    $b = (int) (140 + (1 - $depth) * 60);
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
                }
            }
            usleep(40000);
        }

        // Rivers from two mountains to ocean
        $riverSources = [(int) ($this->termWidth * 0.4), (int) ($this->termWidth * 0.55)];
        foreach ($riverSources as $rCol) {
            $col = $rCol;
            for ($row = $groundRow; $row < $oceanTop; $row++) {
                $col += rand(-1, 1);
                $col = max(1, min($this->termWidth - 1, $col));
                if ($this->inBounds($row, $col)) {
                    echo Theme::moveTo($row, $col).Theme::rgb(40, 80, 180).'│'.$r;
                    usleep(15000);
                }
            }
        }

        // Trees at various positions
        $treePositions = [
            (int) ($this->termWidth * 0.15),
            (int) ($this->termWidth * 0.3),
            (int) ($this->termWidth * 0.5),
            (int) ($this->termWidth * 0.7),
            (int) ($this->termWidth * 0.85),
        ];
        $leafColors = [[30, 120, 50], [40, 140, 60], [50, 130, 40], [35, 150, 55], [45, 110, 45]];
        foreach ($treePositions as $ti => $tCol) {
            $tBase = $groundRow - 1;
            $tHeight = rand(2, 4);
            $trunk = Theme::rgb(100 + rand(0, 30), 60 + rand(0, 20), 30);
            for ($h = 0; $h < $tHeight; $h++) {
                $row = $tBase - $h;
                if ($this->inBounds($row, $tCol)) {
                    echo Theme::moveTo($row, $tCol).$trunk.'│'.$r;
                }
            }
            // Canopy
            $lc = $leafColors[$ti % count($leafColors)];
            $leaves = Theme::rgb(...$lc);
            $canopyWidth = rand(2, 4);
            for ($cy = -2; $cy <= 0; $cy++) {
                $cRow = $tBase - $tHeight + $cy;
                $cw = $canopyWidth - abs($cy);
                for ($cOff = -$cw; $cOff <= $cw; $cOff++) {
                    $cCol = $tCol + $cOff;
                    if ($this->inBounds($cRow, $cCol)) {
                        echo Theme::moveTo($cRow, $cCol).$leaves.'◆'.$r;
                    }
                }
            }
            usleep(40000);
        }

        // Vegetation — flowers along terrain
        usleep(200000);
        $flowerChars = ['·', '✿', '❀', '✾', '·', '·'];
        $flowerColors = [[200, 100, 120], [220, 180, 60], [180, 80, 160], [100, 180, 120], [200, 200, 80]];
        for ($i = 0; $i < 30; $i++) {
            $col = rand(3, $this->termWidth - 3);
            $row = $groundRow;
            if ($this->inBounds($row, $col)) {
                $fc = $flowerColors[array_rand($flowerColors)];
                echo Theme::moveTo($row, $col).Theme::rgb(...$fc).$flowerChars[array_rand($flowerChars)].$r;
            }
            usleep(15000);
        }

        // Birds flying across
        $birdRow = (int) ($this->termHeight * 0.2);
        for ($bCol = 5; $bCol < $this->termWidth - 5; $bCol += 4) {
            if ($bCol > 5) {
                echo Theme::moveTo($birdRow, $bCol - 4).'   ';
                echo Theme::moveTo($birdRow - 1, $bCol - 3).'   ';
            }
            echo Theme::moveTo($birdRow, $bCol).Theme::rgb(60, 60, 70).'─v─'.$r;
            $birdRow += (rand(0, 2) === 0 ? -1 : 0);
            $birdRow = max(2, min($this->termHeight - 5, $birdRow));
            usleep(12000);
        }

        // Narration
        $this->typeNarration('Gaia, broad-breasted Earth, rose as the foundation of all things.', Theme::rgb(60, 120, 60));
        usleep(800000);
    }

    // ──────────────────────────────────────────────────────
    // V. ΤΙΤΑΝΕΣ — The Titans (~9 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseTitans(): void
    {
        $r = Theme::reset();

        // 12 Titans in 2 rows
        $titans = [
            // Row 1
            ['Κρόνος', '♄', [180, 160, 120]],
            ['Ρέα', '♁', [120, 160, 100]],
            ['Ωκεανός', '≈', [60, 120, 200]],
            ['Θεία', '☉', [255, 200, 80]],
            ['Ὑπερίων', '✦', [255, 180, 60]],
            ['Κοῖος', '⊛', [160, 140, 200]],
            // Row 2
            ['Φοίβη', '☽', [180, 180, 220]],
            ['Τηθύς', '∿', [80, 150, 200]],
            ['Μνημοσύνη', '◈', [200, 160, 220]],
            ['Θέμις', '⚖', [180, 180, 140]],
            ['Κρεῖος', '✧', [160, 120, 100]],
            ['Ἰαπετός', '⊹', [140, 100, 80]],
        ];

        $row1 = $this->cy - 4;
        $row2 = $this->cy + 2;
        $colSpacing = max(10, (int) ($this->termWidth / 7));

        foreach ($titans as $i => [$name, $symbol, $rgb]) {
            $row = ($i < 6) ? $row1 : $row2;
            $colIdx = ($i < 6) ? $i : $i - 6;
            $col = $colSpacing + ($colIdx * $colSpacing);
            $col = max(3, min($col, $this->termWidth - 12));
            [$rv, $g, $b] = $rgb;

            // Footstep ripple before appearing
            for ($ripple = 1; $ripple <= 3; $ripple++) {
                $this->drawRing($row, $col, $ripple, '·', [(int) ($rv * 0.3), (int) ($g * 0.3), (int) ($b * 0.3)]);
                usleep(20000);
            }

            // Symbol materializes
            foreach ([0.2, 0.4, 0.7, 1.0] as $scale) {
                $cr = min(255, (int) ($rv * $scale));
                $cg = min(255, (int) ($g * $scale));
                $cb = min(255, (int) ($b * $scale));
                $char = $scale < 0.4 ? '·' : $symbol;
                echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$char.$r;
                usleep(40000);
            }

            // Name below
            $nameCol = max(1, $col - (int) (mb_strwidth($name) / 2));
            echo Theme::moveTo($row + 1, $nameCol).Theme::rgb((int) ($rv * 0.5), (int) ($g * 0.5), (int) ($b * 0.5)).$name.$r;

            // Clean up ripples
            for ($ripple = 1; $ripple <= 3; $ripple++) {
                $this->drawRing($row, $col, $ripple, ' ', [0, 0, 0], true);
            }

            usleep(60000);
        }

        // Element effects for key Titans
        usleep(300000);

        // Oceanos: water ripples
        $oceanosCol = $colSpacing + 2 * $colSpacing;
        for ($w = 0; $w < 8; $w++) {
            $wCol = $oceanosCol + rand(-4, 4);
            $wRow = $row1 + rand(-1, 1);
            if ($this->inBounds($wRow, $wCol)) {
                echo Theme::moveTo($wRow, $wCol).Theme::rgb(60, 120, 200).['≈', '∿', '~'][rand(0, 2)].$r;
            }
            usleep(30000);
        }

        // Hyperion: light beams
        $hyperionCol = $colSpacing + 4 * $colSpacing;
        $beamDirs = [[-1, -2], [-1, 0], [-1, 2], [0, -3], [0, 3]];
        foreach ($beamDirs as [$dr, $dc]) {
            for ($d = 1; $d <= 3; $d++) {
                $bRow = $row1 + $dr * $d;
                $bCol = $hyperionCol + $dc * $d;
                if ($this->inBounds($bRow, $bCol)) {
                    $bright = 255 - $d * 40;
                    echo Theme::moveTo($bRow, $bCol).Theme::rgb($bright, (int) ($bright * 0.7), (int) ($bright * 0.2)).'·'.$r;
                }
            }
            usleep(25000);
        }

        // Ground cracks between Titans
        usleep(200000);
        $crackColor = Theme::rgb(50, 30, 20);
        for ($crack = 0; $crack < 5; $crack++) {
            $cCol = rand((int) ($this->termWidth * 0.15), (int) ($this->termWidth * 0.85));
            $cRow = $row2 + 3;
            $this->drawTendril($cRow, $cCol, rand(5, 12), rand(60, 120), $crackColor);
            usleep(60000);
        }

        // Energy web connecting Titans
        usleep(200000);
        $webColor = Theme::rgb(40, 30, 60);
        $positions = [];
        for ($i = 0; $i < 12; $i++) {
            $row = ($i < 6) ? $row1 : $row2;
            $colIdx = ($i < 6) ? $i : $i - 6;
            $col = $colSpacing + ($colIdx * $colSpacing);
            $positions[] = [$row, min($col, $this->termWidth - 12)];
        }
        // Connect adjacent Titans with dim lines
        for ($i = 0; $i < count($positions) - 1; $i++) {
            if ($i === 5) {
                continue;
            } // Skip row break
            $this->drawLine($positions[$i][0], $positions[$i][1], $positions[$i + 1][0], $positions[$i + 1][1], $webColor, '·');
            usleep(25000);
        }

        // Storm clouds gathering at top
        usleep(200000);
        for ($sRow = 1; $sRow <= min(4, (int) ($this->termHeight * 0.12)); $sRow++) {
            for ($col = 1; $col < $this->termWidth; $col += 2) {
                $v = rand(15, 35);
                echo Theme::moveTo($sRow, $col).Theme::rgb($v, $v, $v + 5).['▒', '░', '▓'][rand(0, 2)].$r;
            }
            usleep(40000);
        }

        // Screen shake
        usleep(300000);
        for ($shake = 0; $shake < 8; $shake++) {
            echo "\033[".(($shake % 2 === 0) ? '1A' : '1B');
            usleep(40000);
        }

        // Narration
        $this->typeNarration('Twelve Titans, children of Gaia and Ouranos, strode across the young world.', Theme::rgb(160, 140, 100));
        usleep(600000);
    }

    // ──────────────────────────────────────────────────────
    // VI. ΤΙΤΑΝΟΜΑΧΙΑ — The War (~12 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseTitanomachy(): void
    {
        $r = Theme::reset();

        // Two-sided battle formation
        $titanSymbols = [['♄', [180, 160, 120]], ['♁', [120, 160, 100]], ['≈', [60, 120, 200]], ['✦', [255, 180, 60]]];
        $olympianSymbols = [['⚡', [255, 220, 80]], ['⊛', [160, 140, 200]], ['☉', [255, 200, 80]], ['☽', [180, 180, 220]]];

        $leftCol = (int) ($this->termWidth * 0.12);
        $rightCol = (int) ($this->termWidth * 0.88);
        $startRow = (int) ($this->termHeight * 0.25);

        // Draw formations
        foreach ($titanSymbols as $i => [$sym, $rgb]) {
            $row = $startRow + $i * 3;
            if ($this->inBounds($row, $leftCol)) {
                echo Theme::moveTo($row, $leftCol).Theme::rgb(...$rgb).$sym.$r;
            }
            usleep(40000);
        }
        foreach ($olympianSymbols as $i => [$sym, $rgb]) {
            $row = $startRow + $i * 3;
            if ($this->inBounds($row, $rightCol)) {
                echo Theme::moveTo($row, $rightCol).Theme::rgb(...$rgb).$sym.$r;
            }
            usleep(40000);
        }

        usleep(300000);

        // Sustained battle loop — 120 frames of chaos
        $battleMid = $this->cx;
        for ($frame = 0; $frame < 120; $frame++) {
            $this->checkSkip();
            // Clash projectiles from both sides
            if ($frame % 8 === 0) {
                $projRow = $startRow + rand(0, 9);
                $projChar = ['⊹', '✦', '⊛', '·'][rand(0, 3)];
                // Left side fires right
                for ($pCol = $leftCol + 2; $pCol < $battleMid; $pCol += 4) {
                    if ($this->inBounds($projRow, $pCol)) {
                        echo Theme::moveTo($projRow, $pCol).Theme::rgb(200, 120, 60).$projChar.$r;
                    }
                }
                // Right side fires left
                $projRow2 = $startRow + rand(0, 9);
                for ($pCol = $rightCol - 2; $pCol > $battleMid; $pCol -= 4) {
                    if ($this->inBounds($projRow2, $pCol)) {
                        echo Theme::moveTo($projRow2, $pCol).Theme::rgb(80, 120, 255).$projChar.$r;
                    }
                }
            }

            // Random explosions at center
            if ($frame % 6 === 0) {
                $eRow = $startRow + rand(-1, 10);
                $eCol = $battleMid + rand(-8, 8);
                if ($this->inBounds($eRow, $eCol)) {
                    $expChars = ['✴', '✳', '❋', '✺'];
                    echo Theme::moveTo($eRow, $eCol).Theme::rgb(255, rand(150, 255), rand(50, 150)).$expChars[array_rand($expChars)].$r;
                    // Debris
                    for ($d = 0; $d < 3; $d++) {
                        $dRow = $eRow + rand(-1, 1);
                        $dCol = $eCol + rand(-2, 2);
                        if ($this->inBounds($dRow, $dCol)) {
                            echo Theme::moveTo($dRow, $dCol).Theme::rgb(255, rand(100, 200), 0).'·'.$r;
                        }
                    }
                }
            }

            // Random lightning
            if ($frame % 15 === 0) {
                $this->drawLightningBolt(rand(1, 3), rand((int) ($this->termWidth * 0.2), (int) ($this->termWidth * 0.8)));
            }

            // Falling debris from top
            if ($frame % 4 === 0) {
                $dCol = rand(1, $this->termWidth - 1);
                $dRow = rand(1, (int) ($this->termHeight * 0.3));
                if ($this->inBounds($dRow, $dCol)) {
                    echo Theme::moveTo($dRow, $dCol).Theme::rgb(rand(60, 120), rand(40, 80), rand(20, 40)).['·', '∙', '˙'][rand(0, 2)].$r;
                }
            }

            // Screen shake every 20 frames
            if ($frame % 20 === 0 && $frame > 0) {
                echo (($frame / 20) % 2 === 0) ? "\033[1A" : "\033[1B";
            }

            usleep(30000);
        }

        // The earth splits — jagged crack down center
        usleep(200000);
        $crackCol = $battleMid;
        for ($row = 2; $row < $this->termHeight - 2; $row++) {
            $crackCol += rand(-1, 1);
            $crackCol = max($battleMid - 5, min($battleMid + 5, $crackCol));
            if ($this->inBounds($row, $crackCol)) {
                echo Theme::moveTo($row, $crackCol).Theme::rgb(200, 80, 20).'║'.$r;
                // Glow on sides
                if ($this->inBounds($row, $crackCol - 1)) {
                    echo Theme::moveTo($row, $crackCol - 1).Theme::rgb(120, 40, 10).'░'.$r;
                }
                if ($this->inBounds($row, $crackCol + 1)) {
                    echo Theme::moveTo($row, $crackCol + 1).Theme::rgb(120, 40, 10).'░'.$r;
                }
            }
            usleep(12000);
        }

        // The wyrm rises from the crack
        usleep(300000);
        if ($this->termWidth > 80 && $this->termHeight > 28) {
            $creatureCol = (int) (($this->termWidth - 24) / 2);
            $creatureTop = (int) ($this->termHeight * 0.3);
            $wyrmColor = Theme::rgb(200, 60, 40);

            foreach (self::WYRM as $i => $line) {
                $row = $creatureTop + $i;
                if ($this->inBounds($row, $creatureCol)) {
                    echo Theme::moveTo($row, $creatureCol).$wyrmColor.$line.$r;
                    usleep(25000);
                }
            }

            // Eyes glow
            usleep(100000);
            $eyeRow = $creatureTop + 2;
            for ($pulse = 0; $pulse < 4; $pulse++) {
                $bright = ($pulse % 2 === 0) ? 255 : 180;
                echo Theme::moveTo($eyeRow, $creatureCol + 10).Theme::rgb($bright, 40, 20).'◉'.$r;
                echo Theme::moveTo($eyeRow, $creatureCol + 16).Theme::rgb($bright, 40, 20).'◉'.$r;
                usleep(80000);
            }

            // Fire breath in both directions
            $breathRow = $creatureTop + 3;
            for ($dir = -1; $dir <= 1; $dir += 2) {
                $startC = ($dir === 1) ? $creatureCol + 22 : $creatureCol - 1;
                for ($f = 0; $f < 18; $f++) {
                    $fCol = $startC + ($f * $dir);
                    if ($this->inBounds($breathRow, $fCol)) {
                        $heat = 1.0 - ($f / 18.0);
                        echo Theme::moveTo($breathRow, $fCol).Theme::rgb(255, (int) (200 * $heat), (int) (80 * $heat)).['~', '≈', '∿', '⊹', '✦', '·'][$f % 6].$r;
                        if (rand(0, 1) && $this->inBounds($breathRow - 1, $fCol)) {
                            echo Theme::moveTo($breathRow - 1, $fCol).Theme::rgb(255, (int) (120 * $heat), 0).'·'.$r;
                        }
                        if (rand(0, 1) && $this->inBounds($breathRow + 1, $fCol)) {
                            echo Theme::moveTo($breathRow + 1, $fCol).Theme::rgb(255, (int) (120 * $heat), 0).'·'.$r;
                        }
                    }
                    usleep(15000);
                }
            }
            usleep(400000);
        }

        // ZEUS'S THUNDERBOLT — the climax
        usleep(300000);
        // Massive bolt from top center, branching wide
        $zeusCol = $this->cx;
        $zeusPositions = [];
        $row = 1;
        $col = $zeusCol;
        for ($i = 0; $i < (int) ($this->termHeight * 0.8); $i++) {
            $row++;
            $col += rand(-3, 3);
            $col = max(3, min($this->termWidth - 3, $col));
            if (! $this->inBounds($row, $col)) {
                break;
            }
            $zeusPositions[] = [$row, $col];
            // Many branches
            if (rand(0, 100) < 35) {
                $bRow = $row;
                $bCol = $col;
                $bDir = rand(0, 1) ? 1 : -1;
                for ($b = 0; $b < rand(4, 8); $b++) {
                    $bRow++;
                    $bCol += $bDir * rand(1, 3);
                    $bCol = max(1, min($this->termWidth - 1, $bCol));
                    if ($this->inBounds($bRow, $bCol)) {
                        $zeusPositions[] = [$bRow, $bCol];
                    }
                }
            }
        }
        // Draw bright
        foreach ($zeusPositions as [$bRow, $bCol]) {
            echo Theme::moveTo($bRow, $bCol).Theme::rgb(255, 255, 255).'⚡'.$r;
        }
        usleep(80000);
        // Yellow afterglow
        foreach ($zeusPositions as [$bRow, $bCol]) {
            echo Theme::moveTo($bRow, $bCol).Theme::rgb(255, 200, 50).'│'.$r;
        }
        usleep(60000);

        // Screen flash
        echo Theme::rgb(255, 255, 255);
        for ($row = 1; $row <= $this->termHeight; $row++) {
            echo Theme::moveTo($row, 1).str_repeat('█', $this->termWidth);
        }
        echo $r;
        usleep(150000);

        // Aftermath: smoke rising
        echo Theme::clearScreen();
        for ($smoke = 0; $smoke < 40; $smoke++) {
            $sRow = $this->termHeight - rand(0, (int) ($this->termHeight * 0.4));
            $sCol = rand(1, $this->termWidth - 1);
            if ($this->inBounds($sRow, $sCol)) {
                $v = rand(20, 50);
                echo Theme::moveTo($sRow, $sCol).Theme::rgb($v, $v, $v).['░', '▒', '·'][rand(0, 2)].$r;
            }
            usleep(15000);
        }

        usleep(300000);

        // Narration on dark screen
        $this->typeNarration('The heavens cracked. The old order fell.', Theme::rgb(200, 100, 60));
        usleep(1000000);
    }

    // ──────────────────────────────────────────────────────
    // VII. ΚΟΣΜΟΚΡΑΤΩΡ — Cosmic Rain resolving to logo
    // ──────────────────────────────────────────────────────

    private function phaseCosmicRain(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $logoWidth = mb_strwidth(self::LOGO_LINES[0]);
        $logoLeft = max(1, (int) (($this->termWidth - $logoWidth) / 2));
        $logoTop = max(3, (int) ($this->termHeight * 0.2));

        $logoMap = [];
        foreach (self::LOGO_LINES as $i => $line) {
            $row = $logoTop + $i;
            foreach (mb_str_split($line) as $j => $char) {
                $col = $logoLeft + $j;
                if ($char !== ' ') {
                    $logoMap[$row][$col] = $char;
                }
            }
        }

        $numStreams = max(15, (int) ($this->termWidth / 4));
        $numStreams = min($numStreams, 40);
        $streams = [];
        for ($s = 0; $s < $numStreams; $s++) {
            $streams[] = [
                'col' => rand(1, $this->termWidth - 1),
                'headRow' => rand(-15, -1),
                'speed' => rand(1, 3),
                'length' => rand(5, 14),
                'active' => true,
            ];
        }

        $locked = [];
        $totalFrames = 180;

        for ($frame = 0; $frame < $totalFrames; $frame++) {
            $this->checkSkip();
            $lockPhase = $frame > 90;

            foreach ($streams as &$stream) {
                if (! $stream['active']) {
                    continue;
                }

                $stream['headRow'] += $stream['speed'];
                $headRow = $stream['headRow'];
                $col = $stream['col'];

                if ($this->inBounds($headRow, $col)) {
                    $char = self::RAIN_CHARS[array_rand(self::RAIN_CHARS)];
                    if ($lockPhase && isset($logoMap[$headRow][$col]) && ! isset($locked[$headRow][$col])) {
                        $locked[$headRow][$col] = true;
                        $lineIndex = $headRow - $logoTop;
                        if ($lineIndex >= 0 && $lineIndex < 6) {
                            [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIndex];
                            echo Theme::moveTo($headRow, $col).Theme::rgb($rv, $g, $b).$logoMap[$headRow][$col].$r;
                        }
                    } else {
                        echo Theme::moveTo($headRow, $col).Theme::rgb(180, 255, 180).$char.$r;
                    }
                }

                for ($t = 1; $t <= $stream['length']; $t++) {
                    $trailRow = $headRow - $t;
                    if (! $this->inBounds($trailRow, $col) || isset($locked[$trailRow][$col])) {
                        continue;
                    }
                    $fade = max(20, 120 - ($t * 12));
                    echo Theme::moveTo($trailRow, $col).Theme::rgb(0, $fade, 0).'·'.$r;
                }

                $tailRow = $headRow - $stream['length'] - 1;
                if ($this->inBounds($tailRow, $col) && ! isset($locked[$tailRow][$col])) {
                    echo Theme::moveTo($tailRow, $col).' ';
                }

                if ($headRow - $stream['length'] > $this->termHeight) {
                    if ($lockPhase && $frame > 140) {
                        $stream['active'] = false;
                    } else {
                        $stream['headRow'] = rand(-10, -1);
                        $stream['col'] = rand(1, $this->termWidth - 1);
                        $stream['speed'] = rand(1, 3);
                    }
                }
            }
            unset($stream);

            if ($frame > 130) {
                $fillChance = ($frame - 130) * 5;
                foreach ($logoMap as $row => $cols) {
                    foreach ($cols as $col => $char) {
                        if (! isset($locked[$row][$col]) && rand(0, 100) < $fillChance) {
                            $locked[$row][$col] = true;
                            $lineIndex = $row - $logoTop;
                            if ($lineIndex >= 0 && $lineIndex < 6) {
                                [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIndex];
                                echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
                            }
                        }
                    }
                }
            }

            usleep(30000);
        }

        // Clean up rain
        foreach ($streams as $stream) {
            for ($row = 1; $row <= $this->termHeight; $row++) {
                if (! isset($locked[$row][$stream['col']])) {
                    echo Theme::moveTo($row, $stream['col']).' ';
                }
            }
        }

        // Ensure full logo
        foreach ($logoMap as $row => $cols) {
            foreach ($cols as $col => $char) {
                $lineIndex = $row - $logoTop;
                if ($lineIndex >= 0 && $lineIndex < 6) {
                    [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIndex];
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────
    // VII (cont). Logo ignites (~5 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseLogoIgnite(): void
    {
        $r = Theme::reset();
        $logoWidth = mb_strwidth(self::LOGO_LINES[0]);
        $logoLeft = max(1, (int) (($this->termWidth - $logoWidth) / 2));
        $logoTop = max(3, (int) ($this->termHeight * 0.2));

        // Fire rising from below toward logo
        $fireTop = $logoTop + 8;
        $fireBottom = min($this->termHeight - 2, $logoTop + 16);
        $fireRows = $fireBottom - $fireTop;
        if ($fireRows > 2) {
            $this->drawFireSimulation($fireTop, $fireRows, $this->termWidth, 50);
        }

        // Text scramble → color wave
        for ($frame = 0; $frame < 45; $frame++) {
            $this->checkSkip();
            $resolveCol = $frame * 2;

            foreach (self::LOGO_LINES as $lineIdx => $line) {
                $row = $logoTop + $lineIdx;
                $chars = mb_str_split($line);
                [$targetR, $targetG, $targetB] = self::LOGO_GRADIENTS[$lineIdx];

                foreach ($chars as $colIdx => $char) {
                    if ($char === ' ') {
                        continue;
                    }
                    $col = $logoLeft + $colIdx;

                    if ($colIdx < $resolveCol - 8) {
                        $glowProgress = min(1.0, ($resolveCol - 8 - $colIdx) / 20.0);
                        $rv = (int) (255 - (255 - $targetR) * $glowProgress);
                        $g = (int) (255 - (255 - $targetG) * $glowProgress);
                        $b = (int) (255 - (255 - $targetB) * $glowProgress);
                        echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
                    } elseif ($colIdx < $resolveCol) {
                        echo Theme::moveTo($row, $col).Theme::rgb(255, 255, 255).$char.$r;
                    } else {
                        $scrambleChar = self::SCRAMBLE_CHARS[array_rand(self::SCRAMBLE_CHARS)];
                        $v = rand(40, 80);
                        echo Theme::moveTo($row, $col).Theme::rgb($v, $v, $v).$scrambleChar.$r;
                    }
                }
            }
            usleep(35000);
        }

        // Clean logo
        foreach (self::LOGO_LINES as $lineIdx => $line) {
            $row = $logoTop + $lineIdx;
            [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIdx];
            echo Theme::moveTo($row, $logoLeft).Theme::rgb($rv, $g, $b).$line.$r;
        }

        // Animated border with aura pulse
        usleep(100000);
        $this->drawAnimatedBorder($logoTop - 1, $logoLeft - 2, $logoTop + 7, $logoLeft + $logoWidth + 1);

        // Aura pulse — border brightens and dims 3 times
        $borderTop = $logoTop - 1;
        $borderBot = $logoTop + 7;
        $borderLeft = $logoLeft - 2;
        $borderRight = $logoLeft + $logoWidth + 1;
        for ($pulse = 0; $pulse < 3; $pulse++) {
            foreach ([[255, 100, 80], [200, 50, 40], [160, 30, 25]] as [$rv, $g, $b]) {
                $color = Theme::rgb($rv, $g, $b);
                echo Theme::moveTo($borderTop, $borderLeft).$color.'⟡'.$r;
                echo Theme::moveTo($borderTop, $borderRight).$color.'⟡'.$r;
                echo Theme::moveTo($borderBot, $borderLeft).$color.'⟡'.$r;
                echo Theme::moveTo($borderBot, $borderRight).$color.'⟡'.$r;
                usleep(40000);
            }
        }

        // Side pillars
        $pillarLeft = max(1, $logoLeft - 5);
        $pillarRight = min($this->termWidth - 1, $logoLeft + $logoWidth + 4);
        for ($row = $logoTop; $row <= $logoTop + 6; $row++) {
            $progress = ($row - $logoTop) / 6.0;
            $rv = (int) (140 - $progress * 80);
            $g = (int) (30 + $progress * 20);
            $b = (int) (30 + $progress * 60);
            echo Theme::moveTo($row, $pillarLeft).Theme::rgb($rv, $g, $b).'│'.$r;
            echo Theme::moveTo($row, $pillarRight).Theme::rgb($rv, $g, $b).'│'.$r;
            usleep(15000);
        }

        // Title
        $titleRow = $logoTop + 9;
        $title = 'Κοσμοκράτωρ — Ruler of the Cosmos';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen - 4) / 2));
        foreach ([[50, 50, 50], [100, 80, 40], [180, 140, 50], [240, 190, 70], [255, 200, 80]] as [$rv, $g, $b]) {
            echo Theme::moveTo($titleRow, $titleCol).Theme::rgb($rv, $g, $b).'⚡ '.$title.' ⚡'.$r;
            usleep(70000);
        }

        // Planet symbols
        $symbols = ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆', '✦', '☽', '☉', '★', '✧', '⊛', '◈'];
        $symbolColors = [
            [180, 180, 200], [255, 180, 100], [80, 160, 255], [255, 80, 60],
            [255, 200, 130], [210, 180, 140], [130, 210, 230], [70, 100, 220],
            [255, 255, 200], [200, 200, 220], [255, 220, 80], [255, 255, 200],
            [200, 200, 255], [180, 160, 220], [220, 180, 255],
        ];
        $symbolRow = $titleRow + 2;
        $startCol = max(1, (int) (($this->termWidth - (count($symbols) * 4)) / 2));
        foreach ($symbols as $i => $symbol) {
            [$rv, $g, $b] = $symbolColors[$i];
            echo Theme::moveTo($symbolRow, $startCol + ($i * 4)).Theme::rgb(255, 255, 255).$symbol.$r;
            usleep(15000);
            echo Theme::moveTo($symbolRow, $startCol + ($i * 4)).Theme::rgb($rv, $g, $b).$symbol.$r;
            usleep(25000);
        }

        // Embers floating upward around logo
        $this->animateEmbers(20, 40, 18000);
    }

    // ──────────────────────────────────────────────────────
    // VIII. Η ΤΑΞΗ — Cosmic Assembly (~7 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseCosmicAssembly(): void
    {
        $r = Theme::reset();
        $logoTop = max(3, (int) ($this->termHeight * 0.2));
        $orreryTop = $logoTop + 14;
        $availableRows = $this->termHeight - $orreryTop - 2;
        $orreryCy = $orreryTop + (int) ($availableRows / 2);
        $orreryCx = $this->cx;

        $maxRadius = min((int) ($availableRows / 2) - 1, 8);
        if ($maxRadius < 3) {
            return;
        }

        // Nebula patches in background
        for ($i = 0; $i < 25; $i++) {
            $nRow = $orreryCy + rand(-(int) ($availableRows / 2), (int) ($availableRows / 2));
            $nCol = $orreryCx + rand(-20, 20);
            if ($this->inBounds($nRow, $nCol)) {
                $colors = [[40, 20, 60], [20, 30, 50], [50, 20, 40], [30, 25, 55]];
                $nc = $colors[array_rand($colors)];
                echo Theme::moveTo($nRow, $nCol).Theme::rgb(...$nc).'░'.$r;
            }
            usleep(8000);
        }

        // Orbit rings with trailing head
        $orbits = [];
        if ($maxRadius >= 3) {
            $orbits[] = [3, [90, 45, 45]];
        }
        if ($maxRadius >= 5) {
            $orbits[] = [5, [70, 45, 70]];
        }
        if ($maxRadius >= 7) {
            $orbits[] = [7, [50, 45, 90]];
        }

        foreach ($orbits as [$radius, $rgb]) {
            $color = Theme::rgb(...$rgb);
            $headColor = Theme::rgb(200, 200, 255);
            for ($step = 0; $step < 72; $step++) {
                $angle = $step * 5;
                $rad = deg2rad($angle);
                $col = $orreryCx + (int) round($radius * cos($rad) * 2);
                $row = $orreryCy - (int) round($radius * sin($rad));
                if ($this->inBounds($row, $col)) {
                    echo Theme::moveTo($row, $col).$headColor.'·'.$r;
                }
                if ($step > 0) {
                    $prevRad = deg2rad(($step - 1) * 5);
                    $prevCol = $orreryCx + (int) round($radius * cos($prevRad) * 2);
                    $prevRow = $orreryCy - (int) round($radius * sin($prevRad));
                    if ($this->inBounds($prevRow, $prevCol)) {
                        echo Theme::moveTo($prevRow, $prevCol).$color.'·'.$r;
                    }
                }
                usleep(2000);
            }
        }

        // Sun pulse
        usleep(80000);
        foreach ([[160, 120, 30], [200, 160, 50], [240, 200, 70], [255, 230, 100], [255, 220, 80]] as $rgb) {
            echo Theme::moveTo($orreryCy, $orreryCx).Theme::rgb(...$rgb).'☉'.$r;
            usleep(50000);
        }

        // Planets with positions stored for animation
        usleep(100000);
        $planets = [
            ['☿', 3, 50, [180, 180, 200]],
            ['♀', 3, 200, [255, 180, 100]],
            ['♁', 5, 80, [80, 160, 255]],
            ['♂', 5, 240, [255, 80, 60]],
            ['♃', 7, 15, [255, 200, 130]],
            ['♄', 7, 110, [210, 180, 140]],
            ['♅', 7, 200, [130, 210, 230]],
            ['♆', 7, 310, [70, 100, 220]],
        ];

        foreach ($planets as [$symbol, $orbit, $angle, $rgb]) {
            if ($orbit > $maxRadius) {
                continue;
            }
            $rad = deg2rad($angle);
            $col = $orreryCx + (int) round($orbit * cos($rad) * 2);
            $row = $orreryCy - (int) round($orbit * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).Theme::rgb(255, 255, 255).$symbol.$r;
                usleep(30000);
                echo Theme::moveTo($row, $col).Theme::rgb(...$rgb).$symbol.$r;
            }
            usleep(40000);
        }

        // Animated orbiting — planets move for 60 frames
        for ($frame = 0; $frame < 60; $frame++) {
            foreach ($planets as [$symbol, $orbit, $baseAngle, $rgb]) {
                if ($orbit > $maxRadius) {
                    continue;
                }
                $speed = 4.0 / $orbit; // Inner planets move faster
                $oldAngle = $baseAngle + ($frame - 1) * $speed;
                $newAngle = $baseAngle + $frame * $speed;

                // Erase old
                $oldRad = deg2rad($oldAngle);
                $oldCol = $orreryCx + (int) round($orbit * cos($oldRad) * 2);
                $oldRow = $orreryCy - (int) round($orbit * sin($oldRad));
                if ($this->inBounds($oldRow, $oldCol)) {
                    // Redraw orbit dot
                    $orbitRgb = $orbits[array_search($orbit, array_column($orbits, 0)) ?: 0][1] ?? [50, 40, 50];
                    echo Theme::moveTo($oldRow, $oldCol).Theme::rgb(...$orbitRgb).'·'.$r;
                }

                // Draw new
                $newRad = deg2rad($newAngle);
                $newCol = $orreryCx + (int) round($orbit * cos($newRad) * 2);
                $newRow = $orreryCy - (int) round($orbit * sin($newRad));
                if ($this->inBounds($newRow, $newCol)) {
                    echo Theme::moveTo($newRow, $newCol).Theme::rgb(...$rgb).$symbol.$r;
                }
            }
            usleep(30000);
        }

        // Shooting stars
        for ($comet = 0; $comet < 3; $comet++) {
            $cRow = $orreryCy - (int) ($availableRows / 2) + rand(0, 3);
            $cCol = rand(1, (int) ($this->termWidth * 0.3));
            for ($s = 0; $s < 12; $s++) {
                $sRow = $cRow + $s;
                $sCol = $cCol + $s * 2;
                if ($this->inBounds($sRow, $sCol)) {
                    echo Theme::moveTo($sRow, $sCol).Theme::rgb(255, 255, 200).'✦'.$r;
                    if ($s > 0 && $this->inBounds($sRow - 1, $sCol - 2)) {
                        echo Theme::moveTo($sRow - 1, $sCol - 2).Theme::rgb(100, 100, 80).'·'.$r;
                    }
                    if ($s > 1 && $this->inBounds($sRow - 2, $sCol - 4)) {
                        echo Theme::moveTo($sRow - 2, $sCol - 4).' ';
                    }
                }
                usleep(10000);
            }
            usleep(100000);
        }

        // Zodiac ring
        if ($this->termHeight > 40) {
            $this->drawZodiacRing($orreryCy, $orreryCx, $availableRows);
        }

        // Narration
        $this->typeNarration('The planets found their orbits. The cosmos breathed.', Theme::rgb(100, 140, 200));
        usleep(600000);
    }

    // ──────────────────────────────────────────────────────
    // Epilogue (~6 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseEpilogue(): void
    {
        $r = Theme::reset();
        $logoWidth = mb_strwidth(self::LOGO_LINES[0]);
        $logoLeft = max(1, (int) (($this->termWidth - $logoWidth) / 2));
        $logoTop = max(3, (int) ($this->termHeight * 0.2));

        // Ornamental side columns
        $colLeft = max(1, $logoLeft - 6);
        $colRight = min($this->termWidth - 1, $logoLeft + $logoWidth + 5);
        $colTop = $logoTop - 1;
        $colBot = min($this->termHeight - 4, $logoTop + 15);
        for ($row = $colTop; $row <= $colBot; $row++) {
            $progress = ($row - $colTop) / max(1, $colBot - $colTop);
            $rv = (int) (130 - $progress * 90);
            $g = (int) (30 + $progress * 20);
            $b = (int) (40 + $progress * 80);
            $color = Theme::rgb($rv, $g, $b);
            echo Theme::moveTo($row, $colLeft).$color.'│'.$r;
            echo Theme::moveTo($row, $colRight).$color.'│'.$r;
            usleep(8000);
        }
        // Column caps
        $capColor = Theme::rgb(180, 80, 60);
        echo Theme::moveTo($colTop - 1, $colLeft).$capColor.'◆'.$r;
        echo Theme::moveTo($colTop - 1, $colRight).$capColor.'◆'.$r;
        echo Theme::moveTo($colBot + 1, $colLeft).Theme::rgb(40, 50, 120).'◆'.$r;
        echo Theme::moveTo($colBot + 1, $colRight).Theme::rgb(40, 50, 120).'◆'.$r;

        // Multiple logo breathing pulses
        for ($breath = 0; $breath < 3; $breath++) {
            foreach ([[1.2, 1.2, 1.2], [1.4, 1.4, 1.4], [1.2, 1.2, 1.2], [1.0, 1.0, 1.0]] as [$rm, $gm, $bm]) {
                foreach (self::LOGO_LINES as $lineIdx => $line) {
                    $row = $logoTop + $lineIdx;
                    [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIdx];
                    $rv = min(255, (int) ($rv * $rm));
                    $g = min(255, (int) ($g * $gm));
                    $b = min(255, (int) ($b * $bm));
                    echo Theme::moveTo($row, $logoLeft).Theme::rgb($rv, $g, $b).$line.$r;
                }
                usleep(80000);
            }
        }

        // Corner ornaments
        $logoRight = $logoLeft + $logoWidth + 1;
        $logoBottom = $logoTop + 7;
        $corners = [[$logoTop - 1, $logoLeft - 2], [$logoTop - 1, $logoRight], [$logoBottom, $logoLeft - 2], [$logoBottom, $logoRight]];
        usleep(150000);
        foreach ([[200, 140, 40], [255, 180, 60], [255, 220, 100], [255, 200, 80]] as [$rv, $g, $b]) {
            foreach ($corners as [$row, $col]) {
                if ($this->inBounds($row, $col)) {
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).'⟡'.$r;
                }
            }
            usleep(50000);
        }

        // Final color sweep across screen
        usleep(200000);
        for ($sweepCol = 1; $sweepCol <= $this->termWidth + 4; $sweepCol += 3) {
            for ($row = 1; $row <= $this->termHeight; $row += 3) {
                if ($this->inBounds($row, $sweepCol)) {
                    echo Theme::moveTo($row, $sweepCol).Theme::rgb(60, 30, 30).'·'.$r;
                }
            }
            usleep(3000);
        }

        // Tagline
        usleep(300000);
        $tagRow = $logoTop + 15;
        if ($this->termHeight <= 30) {
            $tagRow = $logoTop + 13;
        }
        $tagText = 'Your AI coding agent';
        $tagBy = ' by ';
        $tagCompany = 'OpenCompany';
        $tagLen = mb_strwidth($tagText.$tagBy.$tagCompany);
        $tagCol = max(1, (int) (($this->termWidth - $tagLen) / 2));

        echo Theme::moveTo($tagRow, $tagCol);
        foreach (mb_str_split($tagText) as $char) {
            echo Theme::text().$char.$r;
            usleep(25000);
        }
        echo Theme::text().$tagBy.$r;
        usleep(100000);
        echo Theme::bold().Theme::white().$tagCompany.$r;

        // Closing line
        usleep(500000);
        $closingRow = $tagRow + 2;
        $closing = '━━━━━ ⟡ ━━━━━';
        $closingCol = max(1, (int) (($this->termWidth - mb_strwidth($closing)) / 2));
        echo Theme::moveTo($closingRow, $closingCol).Theme::rgb(120, 50, 40).$closing.$r;

        usleep(2000000);
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    /** Check whether a (row, col) position falls within the terminal viewport. */
    private function inBounds(int $row, int $col): bool
    {
        return $row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth;
    }

    /** Create a random drifting chaos particle with velocity and finite lifespan. */
    private function spawnChaosParticle(): array
    {
        return [
            'row' => (float) rand(1, $this->termHeight),
            'col' => (float) rand(1, $this->termWidth - 1),
            'vRow' => (rand(-10, 10) / 30.0),
            'vCol' => (rand(-10, 10) / 30.0),
            'char' => self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)],
            'life' => rand(10, 40),
            'maxLife' => 40,
        ];
    }

    /** Draw a jagged tendril from a starting point at a given angle. */
    private function drawTendril(int $startRow, int $startCol, int $length, int $angle, string $color): void
    {
        $r = Theme::reset();
        $row = $startRow;
        $col = $startCol;
        $rad = deg2rad($angle);
        $tendrilChars = ['░', '▒', '▓', '·'];

        for ($i = 0; $i < $length; $i++) {
            $row += (int) round(sin($rad));
            $col += (int) round(cos($rad) * 2);
            // Jitter
            $row += rand(-1, 1);
            $col += rand(-1, 1);
            $rad += (rand(-20, 20) / 180.0) * M_PI;

            if (! $this->inBounds($row, $col)) {
                break;
            }

            $char = $tendrilChars[$i % count($tendrilChars)];
            echo Theme::moveTo($row, $col).$color.$char.$r;
            usleep(3000);
        }
    }

    /** Draw a straight line between two points using Bresenham-style interpolation. */
    private function drawLine(int $r1, int $c1, int $r2, int $c2, string $color, string $char): void
    {
        $r = Theme::reset();
        $steps = max(abs($r2 - $r1), abs($c2 - $c1));
        if ($steps === 0) {
            return;
        }

        for ($i = 0; $i <= $steps; $i++) {
            $row = $r1 + (int) round(($r2 - $r1) * $i / $steps);
            $col = $c1 + (int) round(($c2 - $c1) * $i / $steps);
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).$color.$char.$r;
            }
        }
    }

    /** Type a narration string centered on the bottom of the screen. */
    private function typeNarration(string $text, string $color): void
    {
        $r = Theme::reset();
        $textLen = mb_strwidth($text);
        $col = max(1, (int) (($this->termWidth - $textLen) / 2));
        echo Theme::moveTo($this->termHeight - 2, $col);
        foreach (mb_str_split($text) as $char) {
            echo $color.$char.$r;
            usleep(22000);
        }
    }

    /** Animate glowing embers floating upward from a lower region. */
    private function animateEmbers(int $count, int $frames, int $frameDelay): void
    {
        $r = Theme::reset();
        $embers = [];
        for ($i = 0; $i < $count; $i++) {
            $embers[] = [
                'row' => (float) rand((int) ($this->termHeight * 0.3), $this->termHeight - 2),
                'col' => (float) rand(1, $this->termWidth - 1),
                'vRow' => -(0.2 + rand(0, 50) / 100.0),
                'vCol' => (rand(-20, 20) / 50.0),
                'life' => rand(10, $frames - 5),
                'char' => ['·', '∙', '⊹'][rand(0, 2)],
            ];
        }

        for ($frame = 0; $frame < $frames; $frame++) {
            foreach ($embers as &$e) {
                if ($e['life'] <= 0) {
                    continue;
                }
                $oldRow = (int) ($e['row'] - $e['vRow']);
                $oldCol = (int) ($e['col'] - $e['vCol']);
                if ($this->inBounds($oldRow, $oldCol)) {
                    echo Theme::moveTo($oldRow, $oldCol).' ';
                }
                $row = (int) $e['row'];
                $col = (int) $e['col'];
                if ($this->inBounds($row, $col)) {
                    $bright = (int) (80 + ($e['life'] / (float) $frames) * 175);
                    echo Theme::moveTo($row, $col).Theme::rgb(min(255, $bright), (int) ($bright * 0.5), 0).$e['char'].$r;
                }
                $e['row'] += $e['vRow'];
                $e['col'] += $e['vCol'];
                $e['life']--;
            }
            unset($e);
            usleep($frameDelay);
        }
    }

    /** Cellular-automata fire simulation rendered as ANSI characters. */
    private function drawFireSimulation(int $topRow, int $rows, int $width, int $frames): void
    {
        $r = Theme::reset();
        $fireChars = ['·', '~', '∿', '^', '*', '#', '▒', '░'];
        $heat = [];
        for ($row = 0; $row < $rows; $row++) {
            $heat[$row] = array_fill(0, $width, 0.0);
        }

        for ($frame = 0; $frame < $frames; $frame++) {
            // Seed bottom
            for ($col = 0; $col < $width; $col++) {
                $heat[$rows - 1][$col] = (rand(0, 100) < 65) ? (rand(50, 100) / 100.0) : 0.0;
            }
            // Propagate upward
            for ($row = 0; $row < $rows - 1; $row++) {
                for ($col = 0; $col < $width; $col++) {
                    $below = $heat[$row + 1][$col] ?? 0;
                    $left = $heat[$row + 1][max(0, $col - 1)] ?? 0;
                    $right = $heat[$row + 1][min($width - 1, $col + 1)] ?? 0;
                    $heat[$row][$col] = ($below + $left + $right) / 3.2 + (rand(-3, 3) / 100.0);
                    $heat[$row][$col] = max(0.0, min(1.0, $heat[$row][$col]));
                }
            }
            // Render every other frame
            if ($frame % 2 === 0) {
                for ($row = 0; $row < $rows; $row++) {
                    $screenRow = $topRow + $row;
                    for ($col = 1; $col < $width; $col += 3) {
                        $h = $heat[$row][$col];
                        if ($h < 0.08) {
                            continue;
                        }
                        $charIdx = min((int) ($h * (count($fireChars) - 1)), count($fireChars) - 1);
                        if ($h < 0.3) {
                            $rv = (int) (80 + $h * 500);
                            $g = 0;
                            $b = 0;
                        } elseif ($h < 0.6) {
                            $rv = 255;
                            $g = (int) (($h - 0.3) * 600);
                            $b = 0;
                        } else {
                            $rv = 255;
                            $g = (int) (180 + ($h - 0.6) * 180);
                            $b = (int) (($h - 0.6) * 200);
                        }
                        $rv = max(0, min(255, $rv));
                        $g = max(0, min(255, $g));
                        $b = max(0, min(255, $b));
                        if ($this->inBounds($screenRow, $col)) {
                            echo Theme::moveTo($screenRow, $col).Theme::rgb($rv, $g, $b).$fireChars[$charIdx].$r;
                        }
                    }
                }
            }
            usleep(45000);
        }
    }

    /** Draw (or erase) an elliptical ring at a given center and radius. */
    private function drawRing(int $cy, int $cx, int $radius, string $char, array $rgb, bool $erase = false): void
    {
        $r = Theme::reset();
        $color = $erase ? '' : Theme::rgb(...$rgb);
        $output = $erase ? ' ' : ($color.$char.$r);
        for ($angle = 0; $angle < 360; $angle += 8) {
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).$output;
            }
        }
    }

    /** Draw a branching lightning bolt that flashes white then fades to blue. */
    private function drawLightningBolt(int $startRow, int $startCol): void
    {
        $r = Theme::reset();
        $positions = [];
        $row = $startRow;
        $col = $startCol;
        $steps = rand(8, 14);
        for ($i = 0; $i < $steps; $i++) {
            $row++;
            $col += rand(-2, 2);
            $col = max(1, min($this->termWidth - 1, $col));
            if (! $this->inBounds($row, $col)) {
                break;
            }
            $positions[] = [$row, $col];
            if (rand(0, 100) < 15 && $i > 2) {
                $bRow = $row;
                $bCol = $col;
                for ($b = 0; $b < rand(3, 5); $b++) {
                    $bRow++;
                    $bCol += rand(-2, 2) + (rand(0, 1) ? 1 : -1);
                    $bCol = max(1, min($this->termWidth - 1, $bCol));
                    if ($this->inBounds($bRow, $bCol)) {
                        $positions[] = [$bRow, $bCol];
                    }
                }
            }
        }
        foreach ($positions as [$bRow, $bCol]) {
            echo Theme::moveTo($bRow, $bCol).Theme::rgb(255, 255, 255).['│', '╱', '╲', '║', '⚡'][rand(0, 4)].$r;
        }
        usleep(60000);
        foreach ($positions as [$bRow, $bCol]) {
            echo Theme::moveTo($bRow, $bCol).Theme::rgb(60, 80, 160).'│'.$r;
        }
        usleep(40000);
        foreach ($positions as [$bRow, $bCol]) {
            echo Theme::moveTo($bRow, $bCol).' ';
        }
    }

    /** Scatter a batch of random stars across the terminal. */
    private function drawStarfield(int $numStars): void
    {
        $r = Theme::reset();
        $stars = ['·', '∙', '✧', '⋆', '˙', '✦'];
        for ($i = 0; $i < $numStars; $i++) {
            $row = rand(1, $this->termHeight);
            $col = rand(1, $this->termWidth - 1);
            $bright = rand(0, 100) < 15;
            $v = $bright ? rand(140, 220) : rand(30, 70);
            $color = Theme::rgb($v, $v, min(255, $v + rand(0, 30)));
            echo Theme::moveTo($row, $col).$color.$stars[array_rand($stars)].$r;
            usleep(3000);
        }
    }

    /** Draw a bordered rectangle with animated corner ornaments. */
    private function drawAnimatedBorder(int $top, int $left, int $bottom, int $right): void
    {
        $r = Theme::reset();
        $color = Theme::primaryDim();
        $bright = Theme::rgb(255, 80, 60);

        echo Theme::moveTo($top, $left).$bright.'⟡'.$r;
        usleep(15000);
        for ($col = $left + 1; $col < $right; $col++) {
            echo $color.'━'.$r;
            usleep(1500);
        }
        echo $bright.'⟡'.$r;
        usleep(15000);
        for ($row = $top + 1; $row < $bottom; $row++) {
            echo Theme::moveTo($row, $left).$color.'┃'.$r;
            echo Theme::moveTo($row, $right).$color.'┃'.$r;
            usleep(8000);
        }
        echo Theme::moveTo($bottom, $left).$bright.'⟡'.$r;
        usleep(15000);
        for ($col = $left + 1; $col < $right; $col++) {
            echo $color.'━'.$r;
            usleep(1500);
        }
        echo $bright.'⟡'.$r;
    }

    /** Draw a zodiac ring of 12 signs with connecting arc dots around a center point. */
    private function drawZodiacRing(int $cy, int $cx, int $availableRows): void
    {
        $r = Theme::reset();
        $radius = min((int) ($availableRows / 2) - 1, 11);
        if ($radius < 9) {
            return;
        }

        $signs = [['♈', 0], ['♉', 30], ['♊', 60], ['♋', 90], ['♌', 120], ['♍', 150], ['♎', 180], ['♏', 210], ['♐', 240], ['♑', 270], ['♒', 300], ['♓', 330]];
        $colors = [[220, 80, 80], [140, 160, 100], [200, 180, 100], [80, 120, 180], [220, 120, 60], [120, 160, 80], [180, 160, 200], [120, 60, 80], [200, 100, 60], [100, 130, 80], [100, 150, 200], [80, 100, 160]];

        usleep(150000);
        foreach ($signs as $i => [$sign, $angle]) {
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).Theme::rgb(50, 50, 60).$sign.$r;
                usleep(30000);
                echo Theme::moveTo($row, $col).Theme::rgb(...$colors[$i]).$sign.$r;
            }
            usleep(30000);
        }

        $arcColor = Theme::rgb(35, 30, 50);
        for ($angle = 0; $angle < 360; $angle += 6) {
            if ($angle % 30 < 9 || $angle % 30 > 21) {
                continue;
            }
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).$arcColor.'·'.$r;
            }
            usleep(1500);
        }
    }

    // ──────────────────────────────────────────────────────
    // Skip mechanism
    // ──────────────────────────────────────────────────────

    /** Put STDIN into raw, non-blocking mode for keypress detection. */
    private function enableNonBlockingInput(): void
    {
        if (! defined('STDIN') || ! posix_isatty(STDIN)) {
            return;
        }

        $this->stdinStream = STDIN;
        $this->originalTtyMode = trim((string) shell_exec('stty -g 2>/dev/null'));

        // Raw mode: no echo, no line buffering, no signal handling for input
        shell_exec('stty -icanon -echo 2>/dev/null');
        stream_set_blocking($this->stdinStream, false);
    }

    /** Restore STDIN to its original blocking mode. */
    private function restoreInput(): void
    {
        if ($this->originalTtyMode !== null) {
            shell_exec('stty '.escapeshellarg($this->originalTtyMode).' 2>/dev/null');
            $this->originalTtyMode = null;
        }

        if ($this->stdinStream !== null) {
            stream_set_blocking($this->stdinStream, true);
            $this->stdinStream = null;
        }
    }

    /** Check whether a key has been pressed (non-blocking). */
    private function keyPressed(): bool
    {
        if ($this->stdinStream === null) {
            return false;
        }

        $read = [$this->stdinStream];
        $write = $except = [];

        // Non-blocking check: timeout = 0
        if (@stream_select($read, $write, $except, 0) > 0) {
            // Consume the input
            fread($this->stdinStream, 256);

            return true;
        }

        return false;
    }

    /** Throw IntroSkippedException if user pressed a key. */
    private function checkSkip(): void
    {
        if ($this->keyPressed()) {
            throw new IntroSkippedException;
        }
    }
}
