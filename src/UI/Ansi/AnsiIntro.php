<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

class AnsiIntro
{
    private int $termWidth = 120;

    private int $termHeight = 30;

    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;

        echo Theme::hideCursor() . Theme::clearScreen();

        register_shutdown_function(fn () => print(Theme::showCursor()));

        // Background layer: stars across entire screen
        $this->phaseStarfield();

        // Side decorations (if tall enough for cosmic scene below)
        if ($this->termHeight > 26) {
            $this->phaseColumns();
        }

        // Original intro: logo box, title, planet symbols, tagline
        $this->phaseBorder();
        $this->phaseLogo();
        $this->phaseTitle();
        $this->phasePlanets();
        $this->phaseTagline();

        // Cosmic scene: orrery and zodiac ring
        if ($this->termHeight > 26) {
            $this->phaseOrrery();
        }
        if ($this->termHeight > 38) {
            $this->phaseZodiac();
        }

        // Finishing touches
        $this->phaseGlow();

        echo Theme::moveTo($this->termHeight, 1);
        echo Theme::showCursor();
    }

    public function renderStatic(): void
    {
        $r = Theme::reset();
        $dimRed = Theme::primaryDim();
        $gold = Theme::accent();
        $dim = Theme::text();
        $white = Theme::white();

        $border = $dimRed . '  ⟡ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ ⟡' . $r;
        $side = $dimRed . '  ┃' . $r;
        $sideR = $dimRed . '┃' . $r;

        $lines = [
            '██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗ ',
            '██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗',
            '█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝ ',
            '██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗ ',
            '██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║',
            '╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝',
        ];

        $gradients = [
            [180, 20, 20], [220, 40, 30], [255, 60, 40],
            [255, 80, 50], [220, 40, 30], [160, 20, 20],
        ];

        echo "\n" . $border . "\n";
        echo $side . str_repeat(' ', 95) . $sideR . "\n";
        foreach ($lines as $i => $line) {
            [$rv, $g, $b] = $gradients[$i];
            $color = Theme::rgb($rv, $g, $b);
            echo $side . '  ' . $color . $line . $r . str_repeat(' ', max(0, 93 - mb_strwidth($line))) . $sideR . "\n";
        }
        echo $side . str_repeat(' ', 95) . $sideR . "\n";
        echo $border . "\n\n";
        echo '                      ' . $gold . '⚡ Κοσμοκράτωρ — Ruler of the Cosmos ⚡' . $r . "\n\n";
        echo '                 ☿  ♀  ♁  ♂  ♃  ♄  ♅  ♆  ✦  ☽  ☉  ★  ✧  ⊛  ◈' . "\n\n";
        echo '                        ' . $dim . 'Your AI coding agent by ' . $white . 'OpenCompany' . $r . "\n\n";

        // Zodiac arc
        $signs = ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓'];
        $elementColors = [
            [220, 80, 80],  [140, 160, 100], [200, 180, 100], [80, 120, 180],
            [220, 120, 60], [120, 160, 80],  [180, 160, 200], [120, 60, 80],
            [200, 100, 60], [100, 130, 80],  [100, 150, 200], [80, 100, 160],
        ];

        $zodiac = '                 ';
        foreach ($signs as $i => $sign) {
            $color = Theme::rgb(...$elementColors[$i]);
            $zodiac .= $color . $sign . $r . '  ';
        }
        echo $zodiac . "\n\n";

        // Simple orrery
        $sun = Theme::rgb(255, 220, 80);
        $orbit = Theme::rgb(60, 50, 70);
        $mercury = Theme::rgb(180, 180, 200);
        $venus = Theme::rgb(255, 180, 100);
        $earth = Theme::rgb(80, 160, 255);
        $mars = Theme::rgb(255, 80, 60);
        $jupiter = Theme::rgb(255, 200, 130);
        $saturn = Theme::rgb(210, 180, 140);
        $uranus = Theme::rgb(130, 210, 230);
        $neptune = Theme::rgb(70, 100, 220);

        $orreryLines = [
            "                         {$orbit}·  ·  ·  {$uranus}♅{$r}  {$orbit}·  ·  ·{$r}",
            "                     {$orbit}·{$r}        {$orbit}· {$earth}♁ {$orbit}·{$r}        {$orbit}·{$r}",
            "                  {$orbit}·{$r}     {$orbit}·{$r}    {$orbit}·{$mercury}☿{$orbit}·{$r}    {$orbit}·{$r}     {$orbit}·{$r}",
            "                {$saturn}♄{$r}   {$orbit}·{$r}         {$sun}☉{$r}         {$orbit}·{$r}   {$jupiter}♃{$r}",
            "                  {$orbit}·{$r}     {$orbit}·{$r}    {$orbit}·{$venus}♀{$orbit}·{$r}    {$orbit}·{$r}     {$orbit}·{$r}",
            "                     {$orbit}·{$r}        {$orbit}· {$mars}♂ {$orbit}·{$r}        {$orbit}·{$r}",
            "                         {$orbit}·  ·  ·  {$neptune}♆{$r}  {$orbit}·  ·  ·{$r}",
        ];

        foreach ($orreryLines as $line) {
            echo $line . "\n";
        }
        echo "\n";
    }

    // ──────────────────────────────────────────────────────
    // Animation phases
    // ──────────────────────────────────────────────────────

    private function phaseStarfield(): void
    {
        $stars = ['·', '∙', '✧', '⋆', '˙', '✦', '⊹', '°'];
        $r = Theme::reset();

        // Density scales with terminal size, 1-2% fill
        $numStars = (int) ($this->termWidth * $this->termHeight * 0.018);
        $numStars = max(40, min($numStars, 150));

        for ($i = 0; $i < $numStars; $i++) {
            $row = rand(1, $this->termHeight);
            $col = rand(1, $this->termWidth - 1);

            // Vary brightness — some bright, most dim
            $bright = rand(0, 100) < 15;
            if ($bright) {
                $v = rand(140, 220);
                $b = $v + rand(10, 40);
                $color = Theme::rgb($v, $v, min(255, $b));
            } else {
                $v = rand(40, 90);
                $color = Theme::rgb($v, $v, $v + rand(0, 20));
            }

            echo Theme::moveTo($row, $col) . $color . $stars[array_rand($stars)] . $r;
            usleep(4000);
        }
    }

    private function phaseBorder(): void
    {
        $color = Theme::primaryDim();
        $bright = Theme::rgb(255, 80, 60);
        $r = Theme::reset();

        $bar = '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━';
        $innerWidth = 95;

        echo Theme::moveTo(3, 5) . $bright . '⟡' . $r;
        usleep(30000);
        for ($i = 0; $i < mb_strlen($bar); $i++) {
            echo $color . mb_substr($bar, $i, 1) . $r;
            usleep(3000);
        }
        echo $bright . ' ⟡' . $r;
        usleep(30000);

        $emptyInner = str_repeat(' ', $innerWidth);
        for ($row = 4; $row <= 11; $row++) {
            echo Theme::moveTo($row, 5) . $color . '┃' . $emptyInner . '┃' . $r;
            usleep(20000);
        }

        echo Theme::moveTo(12, 5) . $bright . '⟡' . $r;
        usleep(30000);
        for ($i = 0; $i < mb_strlen($bar); $i++) {
            echo $color . mb_substr($bar, $i, 1) . $r;
            usleep(3000);
        }
        echo $bright . ' ⟡' . $r;
        usleep(50000);
    }

    private function phaseLogo(): void
    {
        $r = Theme::reset();

        $lines = [
            '██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗ ',
            '██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗',
            '█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝ ',
            '██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗ ',
            '██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║',
            '╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝',
        ];

        $gradients = [
            [180, 20, 20], [220, 40, 30], [255, 60, 40],
            [255, 80, 50], [220, 40, 30], [160, 20, 20],
        ];

        foreach ($lines as $i => $line) {
            [$rv, $g, $b] = $gradients[$i];
            $color = Theme::rgb($rv, $g, $b);
            $row = 5 + $i;

            echo Theme::moveTo($row, 8);

            $chars = mb_str_split($line);
            $chunks = array_chunk($chars, 8);
            foreach ($chunks as $chunk) {
                echo $color . implode('', $chunk) . $r;
                usleep(8000);
            }
            usleep(30000);
        }
    }

    private function phaseTitle(): void
    {
        $r = Theme::reset();
        $title = 'Κοσμοκράτωρ — Ruler of the Cosmos';

        $fadeSteps = [
            [60, 60, 60], [100, 60, 40], [160, 80, 30],
            [220, 160, 50], [255, 200, 80],
        ];

        usleep(200000);

        foreach ($fadeSteps as $step) {
            [$rv, $g, $b] = $step;
            $color = Theme::rgb($rv, $g, $b);
            $bolt = Theme::accent();
            echo Theme::moveTo(14, 27) . $bolt . '⚡ ' . $color . $title . $bolt . ' ⚡' . $r;
            usleep(80000);
        }
    }

    private function phasePlanets(): void
    {
        $r = Theme::reset();
        $symbols = ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆', '✦', '☽', '☉', '★', '✧', '⊛', '◈'];

        $colors = [
            [180, 180, 200], [255, 180, 100], [80, 160, 255], [255, 80, 60],
            [255, 200, 130], [210, 180, 140], [130, 210, 230], [70, 100, 220],
            [255, 255, 200], [200, 200, 220], [255, 220, 80], [255, 255, 200],
            [200, 200, 255], [180, 160, 220], [220, 180, 255],
        ];

        usleep(200000);

        $startCol = 23;
        foreach ($symbols as $i => $symbol) {
            [$rv, $g, $b] = $colors[$i];
            $color = Theme::rgb($rv, $g, $b);
            $col = $startCol + ($i * 4);
            echo Theme::moveTo(16, $col) . $color . $symbol . $r;
            usleep(60000);
        }
    }

    private function phaseTagline(): void
    {
        $r = Theme::reset();
        $dim = Theme::text();
        $white = Theme::white();
        $bold = Theme::bold();

        usleep(300000);

        $text = 'Your AI coding agent';
        $by = ' by ';
        $company = 'OpenCompany';

        echo Theme::moveTo(18, 30);
        foreach (mb_str_split($text) as $char) {
            echo $dim . $char . $r;
            usleep(25000);
        }
        echo $dim . $by . $r;
        usleep(100000);
        echo $bold . $white . $company . $r;
    }

    private function phaseColumns(): void
    {
        $r = Theme::reset();
        $startRow = 20;
        $endRow = min($this->termHeight - 2, 50);

        if ($endRow <= $startRow) {
            return;
        }

        // Ornamental caps at top
        $capColor = Theme::rgb(180, 80, 60);
        echo Theme::moveTo($startRow - 1, 3) . $capColor . '◆' . $r;
        echo Theme::moveTo($startRow - 1, $this->termWidth - 3) . $capColor . '◆' . $r;
        usleep(30000);

        for ($row = $startRow; $row <= $endRow; $row++) {
            $progress = ($row - $startRow) / max(1, $endRow - $startRow);

            // Gradient: warm red at top → deep indigo at bottom
            $rv = (int) (130 - $progress * 90);
            $g = (int) (30 + $progress * 20);
            $b = (int) (40 + $progress * 80);
            $color = Theme::rgb($rv, $g, $b);

            echo Theme::moveTo($row, 3) . $color . '│' . $r;
            echo Theme::moveTo($row, $this->termWidth - 3) . $color . '│' . $r;
            usleep(8000);
        }

        // Ornamental caps at bottom
        $capColorBot = Theme::rgb(40, 50, 120);
        echo Theme::moveTo($endRow + 1, 3) . $capColorBot . '◆' . $r;
        echo Theme::moveTo($endRow + 1, $this->termWidth - 3) . $capColorBot . '◆' . $r;
    }

    private function phaseOrrery(): void
    {
        $r = Theme::reset();

        $availableRows = $this->termHeight - 20;
        $cy = 20 + (int) ($availableRows / 2);
        $cx = (int) ($this->termWidth / 2);

        // Scale orbit radii to fit available space
        $maxRadius = min((int) ($availableRows / 2) - 1, 8);
        if ($maxRadius < 3) {
            return;
        }

        // ── Orbit rings (inside out) ──
        $orbits = [];
        if ($maxRadius >= 3) {
            $orbits[] = [3, [90, 45, 45], '·'];
        }
        if ($maxRadius >= 5) {
            $orbits[] = [5, [70, 45, 70], '·'];
        }
        if ($maxRadius >= 7) {
            $orbits[] = [7, [50, 45, 90], '·'];
        }

        foreach ($orbits as [$radius, $rgb, $dot]) {
            $color = Theme::rgb(...$rgb);
            for ($angle = 0; $angle < 360; $angle += 10) {
                $rad = deg2rad($angle);
                $col = $cx + (int) round($radius * cos($rad) * 2);
                $row = $cy - (int) round($radius * sin($rad));
                if ($this->inBounds($row, $col)) {
                    echo Theme::moveTo($row, $col) . $color . $dot . $r;
                }
                usleep(2500);
            }
        }

        // ── Sun: pulse from dim to bright ──
        usleep(80000);
        $sunPulse = [
            [160, 120, 30], [200, 160, 50], [240, 200, 70],
            [255, 230, 100], [255, 220, 80],
        ];
        foreach ($sunPulse as $rgb) {
            echo Theme::moveTo($cy, $cx) . Theme::rgb(...$rgb) . '☉' . $r;
            usleep(50000);
        }

        // ── Planets ──
        usleep(100000);
        $planets = [
            ['☿', 3, 50,  [180, 180, 200]],
            ['♀', 3, 200, [255, 180, 100]],
            ['♁', 5, 80,  [80, 160, 255]],
            ['♂', 5, 240, [255, 80, 60]],
            ['♃', 7, 15,  [255, 200, 130]],
            ['♄', 7, 110, [210, 180, 140]],
            ['♅', 7, 200, [130, 210, 230]],
            ['♆', 7, 310, [70, 100, 220]],
        ];

        foreach ($planets as [$symbol, $orbit, $angle, $rgb]) {
            if ($orbit > $maxRadius) {
                continue;
            }
            $rad = deg2rad($angle);
            $col = $cx + (int) round($orbit * cos($rad) * 2);
            $row = $cy - (int) round($orbit * sin($rad));
            if ($this->inBounds($row, $col)) {
                // Brief flash before settling
                echo Theme::moveTo($row, $col) . Theme::rgb(255, 255, 255) . $symbol . $r;
                usleep(30000);
                echo Theme::moveTo($row, $col) . Theme::rgb(...$rgb) . $symbol . $r;
            }
            usleep(50000);
        }
    }

    private function phaseZodiac(): void
    {
        $r = Theme::reset();

        $availableRows = $this->termHeight - 20;
        $cy = 20 + (int) ($availableRows / 2);
        $cx = (int) ($this->termWidth / 2);

        // Zodiac ring sits outside the outermost orbit
        $radius = min((int) ($availableRows / 2) - 1, 11);
        if ($radius < 9) {
            return;
        }

        // 12 signs at 30° intervals, starting at 0° (east/right)
        $signs = [
            ['♈',   0], ['♉',  30], ['♊',  60], ['♋',  90],
            ['♌', 120], ['♍', 150], ['♎', 180], ['♏', 210],
            ['♐', 240], ['♑', 270], ['♒', 300], ['♓', 330],
        ];

        // Element colors: fire/earth/air/water cycle
        $colors = [
            [220, 80, 80],  [140, 160, 100], [200, 180, 100], [80, 120, 180],
            [220, 120, 60], [120, 160, 80],  [180, 160, 200], [120, 60, 80],
            [200, 100, 60], [100, 130, 80],  [100, 150, 200], [80, 100, 160],
        ];

        usleep(150000);

        foreach ($signs as $i => [$sign, $angle]) {
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                // Fade in: dim → color
                $dimColor = Theme::rgb(50, 50, 60);
                echo Theme::moveTo($row, $col) . $dimColor . $sign . $r;
                usleep(40000);
                echo Theme::moveTo($row, $col) . Theme::rgb(...$colors[$i]) . $sign . $r;
            }
            usleep(40000);
        }

        // Connecting dots between zodiac signs (subtle arc segments)
        $arcColor = Theme::rgb(35, 30, 50);
        for ($angle = 0; $angle < 360; $angle += 6) {
            // Skip positions close to a zodiac sign (within ±8°)
            if ($angle % 30 < 9 || $angle % 30 > 21) {
                continue;
            }
            $rad = deg2rad($angle);
            $col = $cx + (int) round($radius * cos($rad) * 2);
            $row = $cy - (int) round($radius * sin($rad));
            if ($this->inBounds($row, $col)) {
                echo Theme::moveTo($row, $col) . $arcColor . '·' . $r;
            }
            usleep(2000);
        }
    }

    private function phaseGlow(): void
    {
        $r = Theme::reset();

        $positions = [[3, 5], [3, 101], [12, 5], [12, 101]];
        $glowColors = [
            [255, 100, 80], [255, 160, 100], [255, 220, 160],
            [255, 160, 100], [255, 80, 60],
        ];

        usleep(200000);

        foreach ($glowColors as [$rv, $g, $b]) {
            $color = Theme::rgb($rv, $g, $b);
            foreach ($positions as [$row, $col]) {
                echo Theme::moveTo($row, $col) . $color . '⟡' . $r;
            }
            usleep(60000);
        }
    }

    private function inBounds(int $row, int $col): bool
    {
        return $row >= 1 && $row <= $this->termHeight && $col >= 1 && $col < $this->termWidth;
    }
}
