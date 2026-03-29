<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

class AnsiIntro
{
    public function animate(): void
    {
        echo Theme::hideCursor() . Theme::clearScreen();

        register_shutdown_function(fn () => print(Theme::showCursor()));

        $this->phaseStarfield();
        $this->phaseBorder();
        $this->phaseLogo();
        $this->phaseTitle();
        $this->phasePlanets();
        $this->phaseTagline();
        $this->phaseGlow();

        // Move cursor below all animated content (tagline is at row 18)
        echo Theme::moveTo(20, 1);
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
    }

    private function phaseStarfield(): void
    {
        $width = (int) exec('tput cols') ?: 120;
        $height = (int) exec('tput lines') ?: 30;
        $stars = ['·', '∙', '✧', '⋆', '˙'];
        $dim = Theme::dimmer();
        $r = Theme::reset();

        for ($i = 0; $i < 40; $i++) {
            $row = rand(1, $height - 1);
            $col = rand(1, $width - 1);
            echo Theme::moveTo($row, $col) . $dim . $stars[array_rand($stars)] . $r;
            usleep(8000);
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
}
