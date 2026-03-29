<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * The Theogony — KosmoKrator's origin spectacle.
 *
 * A mythological epic told in ANSI, inspired by Hesiod's Theogonia.
 * Eight chapters tracing the birth of the cosmos from primordial Chaos
 * to the enthronement of the Ruler.
 */
class AnsiTheogony
{
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

    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        // I. ΧΑΟΣ — In the beginning, there was nothing.
        $this->titleCard('Ι', 'ΧΑΟΣ', 'In the beginning, there was nothing.');
        $this->phaseChaos();

        // II. ΕΡΕΒΟΣ ΚΑΙ ΝΥΞ — From the void came Darkness and Night.
        $this->fadeToBlack();
        $this->titleCard('ΙΙ', 'ΕΡΕΒΟΣ ΚΑΙ ΝΥΞ', 'From the void came Darkness and Night.');
        $this->phaseErebosAndNyx();

        // III. ΦΩΣ — And then: Light.
        $this->fadeToBlack();
        $this->titleCard('ΙΙΙ', 'ΦΩΣ', 'And then — Light.');
        $this->phaseSpark();

        // IV. ΓΑΙΑ — The Earth took shape.
        $this->fadeToBlack();
        $this->titleCard('ΙV', 'ΓΑΙΑ', 'The Earth took shape beneath the stars.');
        $this->phaseGaia();

        // V. ΤΙΤΑΝΕΣ — The ancient powers awakened.
        $this->fadeToBlack();
        $this->titleCard('V', 'ΤΙΤΑΝΕΣ', 'The ancient powers awakened.');
        $this->phaseTitans();

        // VI. ΤΙΤΑΝΟΜΑΧΙΑ — War shook the heavens.
        $this->fadeToBlack();
        $this->titleCard('VΙ', 'ΤΙΤΑΝΟΜΑΧΙΑ', 'War shook the heavens.');
        $this->phaseTitanomachy();

        // VII. ΚΟΣΜΟΚΡΑΤΩΡ — From the ashes, a Ruler was forged.
        $this->fadeToBlack();
        $this->titleCard('VΙΙ', 'ΚΟΣΜΟΚΡΑΤΩΡ', 'From the ashes, a Ruler was forged.');
        $this->phaseCosmicRain();
        $this->phaseLogoIgnite();

        // VIII. Η ΤΑΞΗ — The cosmos took its eternal shape.
        if ($this->termHeight > 30) {
            $this->titleCard('VΙΙΙ', 'Η ΤΑΞΗ', 'The cosmos took its eternal shape.');
            $this->phaseCosmicAssembly();
        }

        // Epilogue
        $this->phaseEpilogue();

        echo Theme::moveTo($this->termHeight, 1);
        echo Theme::showCursor();
    }

    // ──────────────────────────────────────────────────────
    // Title cards & transitions
    // ──────────────────────────────────────────────────────

    private function titleCard(string $numeral, string $greek, string $subtitle): void
    {
        $r = Theme::reset();

        // Centered title with numeral
        $header = $numeral.'.  '.$greek;
        $headerLen = mb_strwidth($header);
        $subtitleLen = mb_strwidth($subtitle);
        $headerCol = max(1, (int) (($this->termWidth - $headerLen) / 2));
        $subtitleCol = max(1, (int) (($this->termWidth - $subtitleLen) / 2));
        $titleRow = $this->cy - 1;
        $subRow = $this->cy + 1;

        // Decorative line
        $lineWidth = max($headerLen, $subtitleLen) + 8;
        $lineCol = max(1, (int) (($this->termWidth - $lineWidth) / 2));

        // Fade in the header
        $fadeSteps = [
            [30, 20, 20], [60, 30, 25], [100, 40, 30],
            [160, 50, 35], [220, 60, 40], [255, 60, 40],
        ];
        foreach ($fadeSteps as [$rv, $g, $b]) {
            echo Theme::moveTo($titleRow, $headerCol).Theme::rgb($rv, $g, $b).$header.$r;
            usleep(60000);
        }

        // Draw decorative lines
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

        // Type out subtitle
        usleep(200000);
        echo Theme::moveTo($subRow + 1, $subtitleCol);
        $dim = Theme::rgb(140, 130, 120);
        foreach (mb_str_split($subtitle) as $char) {
            echo $dim.$char.$r;
            usleep(30000);
        }

        // Hold for a moment
        usleep(1200000);

        // Fade out
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

    private function fadeToBlack(): void
    {
        usleep(300000);
        echo Theme::clearScreen();
        usleep(400000);
    }

    // ──────────────────────────────────────────────────────
    // I. ΧΑΟΣ — Chaos (3 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseChaos(): void
    {
        $r = Theme::reset();

        $particles = [];
        $maxParticles = (int) ($this->termWidth * $this->termHeight * 0.008);
        $maxParticles = max(20, min($maxParticles, 80));

        $frames = 120;
        $centerPulsePhase = 0;

        for ($frame = 0; $frame < $frames; $frame++) {
            while (count($particles) < $maxParticles) {
                $particles[] = [
                    'row' => rand(1, $this->termHeight),
                    'col' => rand(1, $this->termWidth - 1),
                    'char' => self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)],
                    'life' => rand(8, 30),
                    'maxLife' => 30,
                ];
            }

            foreach ($particles as &$p) {
                if ($p['life'] <= 0) {
                    echo Theme::moveTo($p['row'], $p['col']).' ';
                    $p['row'] = rand(1, $this->termHeight);
                    $p['col'] = rand(1, $this->termWidth - 1);
                    $p['char'] = self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)];
                    $p['life'] = rand(8, 30);

                    continue;
                }

                $brightness = (int) (40 + ($p['life'] / $p['maxLife']) * 50);
                $color = Theme::rgb($brightness, $brightness, $brightness + rand(0, 15));
                echo Theme::moveTo($p['row'], $p['col']).$color.$p['char'].$r;
                $p['life']--;
            }
            unset($p);

            $centerPulsePhase += 0.15;
            $pulse = (int) (60 + sin($centerPulsePhase) * 40);
            $centerChar = $frame < 40 ? '·' : ($frame < 80 ? '∘' : '✦');
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($pulse, $pulse, $pulse + 20).$centerChar.$r;

            usleep(20000);
        }

        foreach ($particles as $p) {
            echo Theme::moveTo($p['row'], $p['col']).' ';
        }
    }

    // ──────────────────────────────────────────────────────
    // II. ΕΡΕΒΟΣ ΚΑΙ ΝΥΞ — Darkness and Night (4 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseErebosAndNyx(): void
    {
        $r = Theme::reset();

        // Darkness creeps in from the edges — waves of dim characters
        // moving inward, then Night unfurls with deep blue/purple hues

        // Phase 1: Erebos (Darkness) — dark waves from edges
        for ($wave = 0; $wave < 8; $wave++) {
            $depth = $wave + 1;
            $brightness = max(10, 40 - $wave * 4);
            $color = Theme::rgb($brightness, $brightness, (int) ($brightness * 0.8));

            // Top and bottom edges
            for ($col = 1; $col < $this->termWidth; $col += 2) {
                $topRow = $depth;
                $botRow = $this->termHeight - $depth + 1;
                $char = ['░', '▒', '▓', '█'][$wave % 4];

                if ($this->inBounds($topRow, $col)) {
                    echo Theme::moveTo($topRow, $col).$color.$char.$r;
                }
                if ($this->inBounds($botRow, $col)) {
                    echo Theme::moveTo($botRow, $col).$color.$char.$r;
                }
            }

            // Left and right edges
            for ($row = $depth; $row <= $this->termHeight - $depth + 1; $row++) {
                if ($this->inBounds($row, $depth)) {
                    echo Theme::moveTo($row, $depth).$color.'▒'.$r;
                }
                $rightCol = $this->termWidth - $depth;
                if ($this->inBounds($row, $rightCol)) {
                    echo Theme::moveTo($row, $rightCol).$color.'▒'.$r;
                }
            }

            usleep(120000);
        }

        // Phase 2: Nyx (Night) — deep blue stars emerge, the void gets color
        usleep(400000);
        $nightStars = (int) ($this->termWidth * $this->termHeight * 0.015);
        $nightStars = max(30, min($nightStars, 100));

        for ($i = 0; $i < $nightStars; $i++) {
            $row = rand(1, $this->termHeight);
            $col = rand(1, $this->termWidth - 1);
            $char = self::CHAOS_CHARS[array_rand(self::CHAOS_CHARS)];

            // Deep blue/purple palette
            $rv = rand(20, 60);
            $g = rand(15, 40);
            $b = rand(60, 140);
            echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
            usleep(8000);
        }

        // A crescent moon appears
        $moonRow = (int) ($this->termHeight * 0.3);
        $moonCol = (int) ($this->termWidth * 0.7);
        $moonColor = Theme::rgb(180, 180, 220);
        $moonFade = [
            [40, 40, 60], [80, 80, 120], [140, 140, 180], [180, 180, 220],
        ];
        foreach ($moonFade as [$rv, $g, $b]) {
            echo Theme::moveTo($moonRow, $moonCol).Theme::rgb($rv, $g, $b).'☽'.$r;
            usleep(100000);
        }

        // Narration below
        $narration = 'Erebos shrouded the abyss. Nyx veiled the heavens.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        $narRow = $this->termHeight - 3;
        echo Theme::moveTo($narRow, $narCol);
        $dim = Theme::rgb(70, 60, 90);
        foreach (mb_str_split($narration) as $char) {
            echo $dim.$char.$r;
            usleep(25000);
        }

        usleep(800000);
    }

    // ──────────────────────────────────────────────────────
    // III. ΦΩΣ — Light / The Spark (5 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseSpark(): void
    {
        $r = Theme::reset();

        // Flash at center
        $flashChars = ['✦', '✴', '✳', '❋', '✺'];
        $flashColors = [
            [255, 255, 255], [255, 255, 200], [255, 240, 160],
            [255, 220, 120], [255, 200, 80],
        ];
        foreach ($flashColors as $j => [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy, $this->cx).Theme::rgb($rv, $g, $b).$flashChars[$j].$r;
            usleep(40000);
        }

        // Shockwave: expanding rings
        $maxRadius = min((int) ($this->termWidth / 4), (int) ($this->termHeight / 2)) - 2;
        $maxRadius = max(5, min($maxRadius, 18));
        $ringChars = ['·', '∘', '○', '◌', '○', '∘', '·'];

        for ($radius = 1; $radius <= $maxRadius; $radius++) {
            $ringChar = $ringChars[$radius % count($ringChars)];
            $brightness = max(80, 255 - ($radius * 10));
            $this->drawRing($this->cy, $this->cx, $radius, $ringChar, [$brightness, $brightness, min(255, $brightness + 30)]);

            if ($radius > 1) {
                $dimBrightness = max(30, 100 - ($radius * 5));
                $this->drawRing($this->cy, $this->cx, $radius - 1, '·', [$dimBrightness, $dimBrightness, $dimBrightness + 15]);
            }

            if ($radius > 3) {
                $this->drawRing($this->cy, $this->cx, $radius - 3, ' ', [0, 0, 0], true);
            }

            usleep(18000);
        }

        for ($radius = max(1, $maxRadius - 2); $radius <= $maxRadius; $radius++) {
            $this->drawRing($this->cy, $this->cx, $radius, ' ', [0, 0, 0], true);
            usleep(8000);
        }

        // Lightning bolts
        usleep(100000);
        $this->drawLightningBolt($this->cy - (int) ($this->termHeight * 0.35), $this->cx - 15);
        usleep(150000);
        $this->drawLightningBolt($this->cy - (int) ($this->termHeight * 0.35), $this->cx + 15);

        // Starfield settles
        usleep(200000);
        $this->drawStarfield(60);

        // Narration
        $narration = 'From the clash of Darkness and Void, Light was born.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        echo Theme::moveTo($this->termHeight - 2, $narCol);
        $dim = Theme::rgb(160, 140, 80);
        foreach (mb_str_split($narration) as $char) {
            echo $dim.$char.$r;
            usleep(22000);
        }

        usleep(800000);
    }

    // ──────────────────────────────────────────────────────
    // IV. ΓΑΙΑ — The Earth (4 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseGaia(): void
    {
        $r = Theme::reset();

        // Draw a landscape at the bottom: earth rising from the void
        $groundRow = (int) ($this->termHeight * 0.7);
        $groundChars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

        // Stars above
        $this->drawStarfield(40);

        // Earth rises from the center outward
        usleep(300000);
        $halfWidth = (int) ($this->termWidth / 2);

        for ($spread = 0; $spread <= $halfWidth; $spread += 2) {
            $leftCol = max(1, $this->cx - $spread);
            $rightCol = min($this->termWidth - 1, $this->cx + $spread);

            for ($col = $leftCol; $col <= $rightCol; $col++) {
                // Height varies sinusoidally for terrain
                $normalizedCol = ($col - $this->cx) / (float) max(1, $halfWidth);
                $terrainHeight = (int) (3 + 2 * sin($normalizedCol * 3.0) + 1.5 * cos($normalizedCol * 7.0));
                $terrainHeight = max(1, min(6, $terrainHeight));

                for ($h = 0; $h < $terrainHeight; $h++) {
                    $row = $groundRow + $h;
                    if (! $this->inBounds($row, $col)) {
                        continue;
                    }

                    // Deep earth colors: brown → green on top
                    $depth = $h / max(1, $terrainHeight - 1);
                    if ($h === 0) {
                        // Topsoil: green
                        $rv = 40 + rand(0, 20);
                        $g = 100 + rand(0, 60);
                        $b = 30 + rand(0, 15);
                    } else {
                        // Underground: browns and reds
                        $rv = (int) (100 + $depth * 60);
                        $g = (int) (50 + (1 - $depth) * 30);
                        $b = (int) (20 + $depth * 20);
                    }

                    $char = $groundChars[min($h, count($groundChars) - 1)];
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).$char.$r;
                }
            }

            usleep(10000);
        }

        // Mountains at key positions
        $mountains = [
            [(int) ($this->termWidth * 0.25), 5],
            [(int) ($this->termWidth * 0.5), 7],
            [(int) ($this->termWidth * 0.75), 4],
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
                    $shade = max(40, 100 - $h * 12);
                    $char = ($h === $height - 1) ? '▲' : '█';
                    echo Theme::moveTo($row, $col).Theme::rgb($shade, $shade + 10, $shade - 10).$char.$r;
                }
            }
            usleep(80000);
        }

        // The great tree: Gaia's symbol
        $treeCol = $this->cx;
        $treeBase = $groundRow - 1;
        $trunk = Theme::rgb(120, 80, 40);
        $leaves = Theme::rgb(40, 140, 60);

        // Trunk
        for ($h = 0; $h < 4; $h++) {
            $row = $treeBase - $h;
            if ($this->inBounds($row, $treeCol)) {
                echo Theme::moveTo($row, $treeCol).$trunk.'║'.$r;
                usleep(40000);
            }
        }

        // Canopy
        $canopyTop = $treeBase - 7;
        $canopyRows = [
            [-7, '    ◆'],
            [-6, '   ◆◆◆'],
            [-5, '  ◆◆◆◆◆'],
            [-4, ' ◆◆◆◆◆◆◆'],
        ];
        foreach ($canopyRows as [$offset, $foliage]) {
            $row = $treeBase + $offset;
            $fCol = $treeCol - (int) (mb_strwidth($foliage) / 2);
            if ($this->inBounds($row, $fCol)) {
                echo Theme::moveTo($row, $fCol).$leaves.$foliage.$r;
                usleep(60000);
            }
        }

        // Narration
        usleep(500000);
        $narration = 'Gaia, broad-breasted Earth, rose as the foundation of all things.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        echo Theme::moveTo($this->termHeight - 1, $narCol);
        foreach (mb_str_split($narration) as $char) {
            echo Theme::rgb(60, 120, 60).$char.$r;
            usleep(20000);
        }

        usleep(1000000);
    }

    // ──────────────────────────────────────────────────────
    // V. ΤΙΤΑΝΕΣ — The Titans (5 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseTitans(): void
    {
        $r = Theme::reset();

        // The twelve Titans emerge — massive symbols filling the screen
        $titans = [
            ['Κρόνος', '♄', [180, 160, 120]], // Kronos - Time
            ['Ρέα', '♁', [120, 160, 100]],    // Rhea - Earth Mother
            ['Ωκεανός', '≈', [60, 120, 200]],  // Oceanos - Ocean
            ['Θεία', '☉', [255, 200, 80]],     // Theia - Sight/Light
            ['Ὑπερίων', '✦', [255, 180, 60]],  // Hyperion - High One
            ['Κοῖος', '⊛', [160, 140, 200]],   // Coeus - Intelligence
        ];

        // Titans appear one by one, growing from small to large
        $spacing = max(12, (int) ($this->termWidth / (count($titans) + 1)));
        $baseRow = $this->cy - 2;

        foreach ($titans as $i => [$name, $symbol, $rgb]) {
            $col = $spacing + ($i * $spacing);
            $col = min($col, $this->termWidth - 15);
            [$rv, $g, $b] = $rgb;

            // Symbol appears small, then pulses larger
            $sizes = [
                [0.3, '·'],
                [0.5, $symbol],
                [0.8, $symbol],
                [1.0, $symbol],
            ];

            foreach ($sizes as [$scale, $char]) {
                $brightness = (int) ($scale * 255);
                $cr = min(255, (int) ($rv * $scale));
                $cg = min(255, (int) ($g * $scale));
                $cb = min(255, (int) ($b * $scale));
                echo Theme::moveTo($baseRow, $col).Theme::rgb($cr, $cg, $cb).$char.$r;
                usleep(60000);
            }

            // Name below
            $nameCol = $col - (int) (mb_strwidth($name) / 2);
            $nameCol = max(1, $nameCol);
            echo Theme::moveTo($baseRow + 2, $nameCol).Theme::rgb((int) ($rv * 0.6), (int) ($g * 0.6), (int) ($b * 0.6)).$name.$r;
            usleep(100000);
        }

        // The ground trembles — screen shake effect
        usleep(500000);
        for ($shake = 0; $shake < 6; $shake++) {
            $offset = ($shake % 2 === 0) ? 1 : -1;
            // Simulate shake by shifting cursor origin briefly
            echo "\033[{$offset}B"; // Move cursor
            usleep(50000);
        }

        // Narration
        $narration = 'Twelve Titans, children of Gaia and Ouranos, strode across the young world.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        echo Theme::moveTo($this->termHeight - 2, $narCol);
        foreach (mb_str_split($narration) as $char) {
            echo Theme::rgb(160, 140, 100).$char.$r;
            usleep(18000);
        }

        usleep(800000);
    }

    // ──────────────────────────────────────────────────────
    // VI. ΤΙΤΑΝΟΜΑΧΙΑ — The War (5 seconds)
    // ──────────────────────────────────────────────────────

    private function phaseTitanomachy(): void
    {
        $r = Theme::reset();

        // Chaotic battle: lightning, explosions, clashing symbols
        // The screen fills with energy

        // Phase 1: Lightning storm — multiple rapid bolts
        for ($bolt = 0; $bolt < 5; $bolt++) {
            $startRow = rand(1, (int) ($this->termHeight * 0.3));
            $startCol = rand((int) ($this->termWidth * 0.1), (int) ($this->termWidth * 0.9));
            $this->drawLightningBolt($startRow, $startCol);
            usleep(80000);
        }

        // Phase 2: Explosions at random positions
        $explosionChars = ['✴', '✳', '❋', '✺', '✹', '※'];
        for ($exp = 0; $exp < 8; $exp++) {
            $eRow = rand(3, $this->termHeight - 3);
            $eCol = rand(5, $this->termWidth - 5);
            $char = $explosionChars[array_rand($explosionChars)];

            // Explosion colors: white flash → orange → red → fade
            $expColors = [
                [255, 255, 255], [255, 200, 80], [255, 100, 40], [200, 50, 30],
            ];
            foreach ($expColors as [$rv, $g, $b]) {
                echo Theme::moveTo($eRow, $eCol).Theme::rgb($rv, $g, $b).$char.$r;
                // Debris around explosion
                for ($d = 0; $d < 4; $d++) {
                    $dRow = $eRow + rand(-2, 2);
                    $dCol = $eCol + rand(-3, 3);
                    if ($this->inBounds($dRow, $dCol)) {
                        $debrisChar = ['·', '∙', '*', '⊹'][rand(0, 3)];
                        echo Theme::moveTo($dRow, $dCol).Theme::rgb($rv, $g, $b).$debrisChar.$r;
                    }
                }
                usleep(40000);
            }
        }

        // Phase 3: The wyrm rises from the chaos
        if ($this->termWidth > 80 && $this->termHeight > 28) {
            $creatureCol = (int) (($this->termWidth - 24) / 2);
            $creatureTop = (int) ($this->termHeight * 0.3);
            $wyrmColor = Theme::rgb(200, 60, 40);
            $wyrmGlow = Theme::rgb(255, 80, 50);

            foreach (self::WYRM as $i => $line) {
                $row = $creatureTop + $i;
                if ($this->inBounds($row, $creatureCol)) {
                    echo Theme::moveTo($row, $creatureCol).$wyrmColor.$line.$r;
                    usleep(30000);
                }
            }
            usleep(200000);

            // Wyrm breathes fire
            $breathRow = $creatureTop + 3;
            $breathCol = $creatureCol + 20;
            $fireChars = ['~', '≈', '∿', '⊹', '✦', '·'];
            for ($f = 0; $f < 15; $f++) {
                $fCol = $breathCol + $f;
                if ($this->inBounds($breathRow, $fCol)) {
                    $heat = 1.0 - ($f / 15.0);
                    $rv = 255;
                    $g = (int) (200 * $heat);
                    $b = (int) (80 * $heat);
                    $char = $fireChars[$f % count($fireChars)];
                    echo Theme::moveTo($breathRow, $fCol).Theme::rgb($rv, $g, $b).$char.$r;
                    // Some fire above and below
                    if (rand(0, 1) && $this->inBounds($breathRow - 1, $fCol)) {
                        echo Theme::moveTo($breathRow - 1, $fCol).Theme::rgb($rv, (int) ($g * 0.6), 0).'·'.$r;
                    }
                    if (rand(0, 1) && $this->inBounds($breathRow + 1, $fCol)) {
                        echo Theme::moveTo($breathRow + 1, $fCol).Theme::rgb($rv, (int) ($g * 0.6), 0).'·'.$r;
                    }
                    usleep(25000);
                }
            }
            usleep(300000);
        }

        // Screen flash — the climax of the war
        echo Theme::rgb(255, 255, 255);
        for ($row = 1; $row <= $this->termHeight; $row++) {
            echo Theme::moveTo($row, 1).str_repeat('█', $this->termWidth);
        }
        echo $r;
        usleep(120000);

        // Narration over the white flash fading
        echo Theme::clearScreen();
        usleep(300000);

        $narration = 'The heavens cracked. The old order fell.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        echo Theme::moveTo($this->cy, $narCol);
        foreach (mb_str_split($narration) as $char) {
            echo Theme::rgb(200, 100, 60).$char.$r;
            usleep(30000);
        }

        usleep(1200000);
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
            $chars = mb_str_split($line);
            foreach ($chars as $j => $char) {
                $col = $logoLeft + $j;
                if ($char !== ' ') {
                    $logoMap[$row][$col] = $char;
                }
            }
        }

        $numStreams = max(12, (int) ($this->termWidth / 5));
        $numStreams = min($numStreams, 35);
        $streams = [];
        for ($s = 0; $s < $numStreams; $s++) {
            $streams[] = [
                'col' => rand(1, $this->termWidth - 1),
                'headRow' => rand(-10, -1),
                'speed' => rand(1, 3),
                'length' => rand(4, 12),
                'active' => true,
            ];
        }

        $locked = [];
        $totalFrames = 160;

        for ($frame = 0; $frame < $totalFrames; $frame++) {
            $lockPhase = $frame > 80;

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
                    $fade = max(20, 120 - ($t * 15));
                    echo Theme::moveTo($trailRow, $col).Theme::rgb(0, $fade, 0).'·'.$r;
                }

                $tailRow = $headRow - $stream['length'] - 1;
                if ($this->inBounds($tailRow, $col) && ! isset($locked[$tailRow][$col])) {
                    echo Theme::moveTo($tailRow, $col).' ';
                }

                if ($headRow - $stream['length'] > $this->termHeight) {
                    if ($lockPhase && $frame > 120) {
                        $stream['active'] = false;
                    } else {
                        $stream['headRow'] = rand(-8, -1);
                        $stream['col'] = rand(1, $this->termWidth - 1);
                        $stream['speed'] = rand(1, 3);
                    }
                }
            }
            unset($stream);

            if ($frame > 120) {
                $fillChance = ($frame - 120) * 4;
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

            usleep(33000);
        }

        // Clean up rain artifacts
        foreach ($streams as $stream) {
            $col = $stream['col'];
            for ($row = 1; $row <= $this->termHeight; $row++) {
                if (! isset($locked[$row][$col])) {
                    echo Theme::moveTo($row, $col).' ';
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
    // VII (cont). Logo ignites with color wave
    // ──────────────────────────────────────────────────────

    private function phaseLogoIgnite(): void
    {
        $r = Theme::reset();
        $logoWidth = mb_strwidth(self::LOGO_LINES[0]);
        $logoLeft = max(1, (int) (($this->termWidth - $logoWidth) / 2));
        $logoTop = max(3, (int) ($this->termHeight * 0.2));

        // Text scramble → color wave
        $scrambleFrames = 40;
        $waveSpeed = 2;

        for ($frame = 0; $frame < $scrambleFrames; $frame++) {
            $resolveCol = $frame * $waveSpeed;

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

            usleep(40000);
        }

        // Clean logo
        foreach (self::LOGO_LINES as $lineIdx => $line) {
            $row = $logoTop + $lineIdx;
            [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIdx];
            echo Theme::moveTo($row, $logoLeft).Theme::rgb($rv, $g, $b).$line.$r;
        }

        // Animated border
        usleep(150000);
        $this->drawAnimatedBorder($logoTop - 1, $logoLeft - 2, $logoTop + 7, $logoLeft + $logoWidth + 1);

        // Title: "Κοσμοκράτωρ — Ruler of the Cosmos"
        $titleRow = $logoTop + 9;
        $title = 'Κοσμοκράτωρ — Ruler of the Cosmos';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen - 4) / 2));

        $fadeSteps = [
            [50, 50, 50], [80, 60, 40], [140, 100, 40],
            [200, 160, 60], [255, 200, 80],
        ];
        foreach ($fadeSteps as [$rv, $g, $b]) {
            echo Theme::moveTo($titleRow, $titleCol).Theme::rgb($rv, $g, $b).'⚡ '.$title.' ⚡'.$r;
            usleep(80000);
        }

        // Planet symbols row
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
            usleep(20000);
            echo Theme::moveTo($symbolRow, $startCol + ($i * 4)).Theme::rgb($rv, $g, $b).$symbol.$r;
            usleep(30000);
        }
    }

    // ──────────────────────────────────────────────────────
    // VIII. Η ΤΑΞΗ — Cosmic Assembly / Order (7 seconds)
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

        // Orbit rings with trailing bright head
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

        // Planets
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
            usleep(50000);
        }

        // Zodiac ring
        if ($this->termHeight > 40) {
            $this->drawZodiacRing($orreryCy, $orreryCx, $availableRows);
        }

        // Narration
        $narration = 'The planets found their orbits. The cosmos breathed.';
        $narLen = mb_strwidth($narration);
        $narCol = max(1, (int) (($this->termWidth - $narLen) / 2));
        echo Theme::moveTo($this->termHeight - 1, $narCol);
        foreach (mb_str_split($narration) as $char) {
            echo Theme::rgb(100, 140, 200).$char.$r;
            usleep(22000);
        }

        usleep(800000);
    }

    // ──────────────────────────────────────────────────────
    // Epilogue
    // ──────────────────────────────────────────────────────

    private function phaseEpilogue(): void
    {
        $r = Theme::reset();
        $logoWidth = mb_strwidth(self::LOGO_LINES[0]);
        $logoLeft = max(1, (int) (($this->termWidth - $logoWidth) / 2));
        $logoTop = max(3, (int) ($this->termHeight * 0.2));

        // Pulse the logo brighter, then back
        foreach ([[1.3, 1.3, 1.3], [1.5, 1.5, 1.5], [1.3, 1.3, 1.3], [1.0, 1.0, 1.0]] as [$rm, $gm, $bm]) {
            foreach (self::LOGO_LINES as $lineIdx => $line) {
                $row = $logoTop + $lineIdx;
                [$rv, $g, $b] = self::LOGO_GRADIENTS[$lineIdx];
                $rv = min(255, (int) ($rv * $rm));
                $g = min(255, (int) ($g * $gm));
                $b = min(255, (int) ($b * $bm));
                echo Theme::moveTo($row, $logoLeft).Theme::rgb($rv, $g, $b).$line.$r;
            }
            usleep(100000);
        }

        // Corner ornaments glow gold
        $logoRight = $logoLeft + $logoWidth + 1;
        $logoBottom = $logoTop + 7;
        $corners = [
            [$logoTop - 1, $logoLeft - 2],
            [$logoTop - 1, $logoRight],
            [$logoBottom, $logoLeft - 2],
            [$logoBottom, $logoRight],
        ];

        usleep(200000);
        foreach ([[200, 140, 40], [255, 180, 60], [255, 220, 100], [255, 200, 80], [255, 180, 60]] as [$rv, $g, $b]) {
            foreach ($corners as [$row, $col]) {
                if ($this->inBounds($row, $col)) {
                    echo Theme::moveTo($row, $col).Theme::rgb($rv, $g, $b).'⟡'.$r;
                }
            }
            usleep(60000);
        }

        // Tagline types in
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

        // Final decorative line
        usleep(500000);
        $closingRow = $tagRow + 2;
        $closing = '━━━━━ ⟡ ━━━━━';
        $closingCol = max(1, (int) (($this->termWidth - mb_strwidth($closing)) / 2));
        echo Theme::moveTo($closingRow, $closingCol).Theme::rgb(120, 50, 40).$closing.$r;

        // Final pause
        usleep(2000000);
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    private function inBounds(int $row, int $col): bool
    {
        return $row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth;
    }

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
                $branchRow = $row;
                $branchCol = $col;
                for ($b = 0; $b < rand(3, 5); $b++) {
                    $branchRow++;
                    $branchCol += rand(-2, 2) + (rand(0, 1) ? 1 : -1);
                    $branchCol = max(1, min($this->termWidth - 1, $branchCol));
                    if ($this->inBounds($branchRow, $branchCol)) {
                        $positions[] = [$branchRow, $branchCol];
                    }
                }
            }
        }

        foreach ($positions as [$bRow, $bCol]) {
            $jitterChar = ['│', '╱', '╲', '║', '⚡'][array_rand(['│', '╱', '╲', '║', '⚡'])];
            echo Theme::moveTo($bRow, $bCol).Theme::rgb(255, 255, 255).$jitterChar.$r;
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

    private function drawStarfield(int $numStars): void
    {
        $r = Theme::reset();
        $stars = ['·', '∙', '✧', '⋆', '˙', '✦'];

        for ($i = 0; $i < $numStars; $i++) {
            $row = rand(1, $this->termHeight);
            $col = rand(1, $this->termWidth - 1);

            $bright = rand(0, 100) < 15;
            if ($bright) {
                $v = rand(140, 220);
                $color = Theme::rgb($v, $v, min(255, $v + 30));
            } else {
                $v = rand(30, 70);
                $color = Theme::rgb($v, $v, $v + rand(0, 15));
            }

            echo Theme::moveTo($row, $col).$color.$stars[array_rand($stars)].$r;
            usleep(3000);
        }
    }

    private function drawAnimatedBorder(int $top, int $left, int $bottom, int $right): void
    {
        $r = Theme::reset();
        $color = Theme::primaryDim();
        $bright = Theme::rgb(255, 80, 60);

        echo Theme::moveTo($top, $left).$bright.'⟡'.$r;
        usleep(20000);
        for ($col = $left + 1; $col < $right; $col++) {
            echo $color.'━'.$r;
            usleep(2000);
        }
        echo $bright.'⟡'.$r;
        usleep(20000);

        for ($row = $top + 1; $row < $bottom; $row++) {
            echo Theme::moveTo($row, $left).$color.'┃'.$r;
            echo Theme::moveTo($row, $right).$color.'┃'.$r;
            usleep(10000);
        }

        echo Theme::moveTo($bottom, $left).$bright.'⟡'.$r;
        usleep(20000);
        for ($col = $left + 1; $col < $right; $col++) {
            echo $color.'━'.$r;
            usleep(2000);
        }
        echo $bright.'⟡'.$r;
    }

    private function drawZodiacRing(int $cy, int $cx, int $availableRows): void
    {
        $r = Theme::reset();
        $radius = min((int) ($availableRows / 2) - 1, 11);
        if ($radius < 9) {
            return;
        }

        $signs = [
            ['♈', 0], ['♉', 30], ['♊', 60], ['♋', 90],
            ['♌', 120], ['♍', 150], ['♎', 180], ['♏', 210],
            ['♐', 240], ['♑', 270], ['♒', 300], ['♓', 330],
        ];

        $colors = [
            [220, 80, 80], [140, 160, 100], [200, 180, 100], [80, 120, 180],
            [220, 120, 60], [120, 160, 80], [180, 160, 200], [120, 60, 80],
            [200, 100, 60], [100, 130, 80], [100, 150, 200], [80, 100, 160],
        ];

        usleep(150000);

        foreach ($signs as $i => [$sign, $angle]) {
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col).Theme::rgb(50, 50, 60).$sign.$r;
                usleep(40000);
                echo Theme::moveTo($row, $col).Theme::rgb(...$colors[$i]).$sign.$r;
            }
            usleep(40000);
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
            usleep(2000);
        }
    }
}
