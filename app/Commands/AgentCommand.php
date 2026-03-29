<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class AgentCommand extends Command
{
    protected $signature = 'agent {--no-animation : Skip the intro animation}';

    protected $description = 'Launch the KosmoKrator coding agent';

    private const ESC = "\033";
    private const HIDE_CURSOR = "\033[?25l";
    private const SHOW_CURSOR = "\033[?25h";
    private const CLEAR = "\033[2J\033[H";

    public function handle(): void
    {
        if ($this->option('no-animation')) {
            $this->renderStatic();
        } else {
            $this->animate();
        }

        $this->inputLoop();
    }

    private function animate(): void
    {
        echo self::HIDE_CURSOR . self::CLEAR;

        register_shutdown_function(fn () => print(self::SHOW_CURSOR));

        $this->phaseStarfield();
        $this->phaseBorder();
        $this->phaseLogo();
        $this->phaseTitle();
        $this->phasePlanets();
        $this->phaseTagline();
        $this->phaseGlow();

        // Move cursor below all animated content (tagline is at row 18)
        echo self::ESC . '[20;1H';
        echo self::SHOW_CURSOR;
    }

    private function phaseStarfield(): void
    {
        $width = (int) exec('tput cols') ?: 120;
        $height = (int) exec('tput lines') ?: 30;
        $stars = ['·', '∙', '✧', '⋆', '˙'];
        $dim = self::ESC . '[38;5;236m';
        $reset = self::ESC . '[0m';

        for ($i = 0; $i < 40; $i++) {
            $row = rand(1, $height - 1);
            $col = rand(1, $width - 1);
            echo self::ESC . "[{$row};{$col}H" . $dim . $stars[array_rand($stars)] . $reset;
            usleep(8000);
        }
    }

    private function phaseBorder(): void
    {
        $r = 160; $g = 30; $b = 30;
        $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
        $bright = self::ESC . '[38;2;255;80;60m';
        $reset = self::ESC . '[0m';

        $bar = '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━';
        $innerWidth = 95;

        // Top border draws left to right
        echo self::ESC . '[3;5H' . $bright . '⟡' . $reset;
        usleep(30000);
        for ($i = 0; $i < mb_strlen($bar); $i++) {
            echo $color . mb_substr($bar, $i, 1) . $reset;
            usleep(3000);
        }
        echo $bright . ' ⟡' . $reset;
        usleep(30000);

        // Side borders
        $emptyInner = str_repeat(' ', $innerWidth);
        for ($row = 4; $row <= 11; $row++) {
            echo self::ESC . "[{$row};5H" . $color . '┃' . $emptyInner . '┃' . $reset;
            usleep(20000);
        }

        // Bottom border
        echo self::ESC . '[12;5H' . $bright . '⟡' . $reset;
        usleep(30000);
        for ($i = 0; $i < mb_strlen($bar); $i++) {
            echo $color . mb_substr($bar, $i, 1) . $reset;
            usleep(3000);
        }
        echo $bright . ' ⟡' . $reset;
        usleep(50000);
    }

    private function phaseLogo(): void
    {
        $reset = self::ESC . '[0m';

        $lines = [
            '██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗ ',
            '██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗',
            '█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝ ',
            '██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗ ',
            '██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║',
            '╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝',
        ];

        // Red gradient: dark at top, bright in middle, dark at bottom
        $gradients = [
            [180, 20, 20],
            [220, 40, 30],
            [255, 60, 40],
            [255, 80, 50],
            [220, 40, 30],
            [160, 20, 20],
        ];

        foreach ($lines as $i => $line) {
            [$r, $g, $b] = $gradients[$i];
            $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
            $row = 5 + $i;

            echo self::ESC . "[{$row};8H";

            // Reveal left to right in chunks
            $chars = mb_str_split($line);
            $chunkSize = 8;
            $chunks = array_chunk($chars, $chunkSize);
            foreach ($chunks as $chunk) {
                echo $color . implode('', $chunk) . $reset;
                usleep(8000);
            }
            usleep(30000);
        }
    }

    private function phaseTitle(): void
    {
        $reset = self::ESC . '[0m';
        $title = 'Κοσμοκράτωρ — Ruler of the Cosmos';

        // Fade in through brightness levels
        $fadeSteps = [
            [60, 60, 60],
            [100, 60, 40],
            [160, 80, 30],
            [220, 160, 50],
            [255, 200, 80],
        ];

        usleep(200000);

        foreach ($fadeSteps as $step) {
            [$r, $g, $b] = $step;
            $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
            $bolt = self::ESC . '[38;2;255;200;80m';
            echo self::ESC . '[14;27H' . $bolt . '⚡ ' . $color . $title . $bolt . ' ⚡' . $reset;
            usleep(80000);
        }
    }

    private function phasePlanets(): void
    {
        $reset = self::ESC . '[0m';
        $symbols = ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆', '✦', '☽', '☉', '★', '✧', '⊛', '◈'];

        $colors = [
            [180, 180, 200], // Mercury - silver
            [255, 180, 100], // Venus - gold
            [80, 160, 255],  // Earth - blue
            [255, 80, 60],   // Mars - red
            [255, 200, 130], // Jupiter - amber
            [210, 180, 140], // Saturn - tan
            [130, 210, 230], // Uranus - cyan
            [70, 100, 220],  // Neptune - deep blue
            [255, 255, 200], // star
            [200, 200, 220], // moon
            [255, 220, 80],  // sun
            [255, 255, 200], // star
            [200, 200, 255], // star
            [180, 160, 220], // mystical
            [220, 180, 255], // mystical
        ];

        usleep(200000);

        $startCol = 23;
        foreach ($symbols as $i => $symbol) {
            [$r, $g, $b] = $colors[$i];
            $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
            $col = $startCol + ($i * 4);
            echo self::ESC . "[16;{$col}H" . $color . $symbol . $reset;
            usleep(60000);
        }
    }

    private function phaseTagline(): void
    {
        $reset = self::ESC . '[0m';
        $dim = self::ESC . '[38;5;245m';
        $white = self::ESC . '[38;2;255;255;255m';
        $bold = self::ESC . '[1m';

        usleep(300000);

        $text = 'Your AI coding agent';
        $by = ' by ';
        $company = 'OpenCompany';

        echo self::ESC . '[18;30H';
        // Type out tagline
        foreach (mb_str_split($text) as $char) {
            echo $dim . $char . $reset;
            usleep(25000);
        }
        echo $dim . $by . $reset;
        usleep(100000);
        echo $bold . $white . $company . $reset;
    }

    private function phaseGlow(): void
    {
        $reset = self::ESC . '[0m';

        // Flash the corner gems
        $positions = ['3;5', '3;101', '12;5', '12;101'];
        $glowColors = [
            [255, 100, 80],
            [255, 160, 100],
            [255, 220, 160],
            [255, 160, 100],
            [255, 80, 60],
        ];

        usleep(200000);

        foreach ($glowColors as [$r, $g, $b]) {
            $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
            foreach ($positions as $pos) {
                echo self::ESC . "[{$pos}H" . $color . '⟡' . $reset;
            }
            usleep(60000);
        }
    }

    private function inputLoop(): void
    {
        $reset = self::ESC . '[0m';
        $dim = self::ESC . '[38;5;245m';
        $red = self::ESC . '[38;2;255;60;40m';
        $white = self::ESC . '[1;37m';

        echo "\n";
        echo $dim . '  Type a message to begin. Press ' . $white . 'Ctrl+C' . $dim . ' to exit.' . $reset . "\n\n";

        while (true) {
            $input = readline($red . '  ⟡ ' . $reset);

            if ($input === false) {
                // Ctrl+D
                break;
            }

            $input = trim($input);

            if ($input === '') {
                continue;
            }

            if (in_array(strtolower($input), ['/quit', '/exit', '/q'])) {
                echo "\n" . $dim . '  Farewell, Kosmokrator.' . $reset . "\n\n";
                break;
            }

            if (strtolower($input) === '/seed') {
                $this->seedMockSession();
                continue;
            }

            // Placeholder response — will be wired to AI SDK later
            echo "\n" . $dim . '  ⏳ Agent processing not yet implemented. Received: ' . $reset . $input . "\n\n";
        }
    }

    private function seedMockSession(): void
    {
        $r = self::ESC . '[0m';
        $dim = self::ESC . '[38;5;240m';
        $gray = self::ESC . '[38;5;245m';
        $white = self::ESC . '[1;37m';
        $red = self::ESC . '[38;2;255;60;40m';
        $green = self::ESC . '[38;2;80;220;100m';
        $yellow = self::ESC . '[38;2;255;200;80m';
        $cyan = self::ESC . '[38;2;100;200;255m';
        $blue = self::ESC . '[38;2;80;140;255m';
        $magenta = self::ESC . '[38;2;200;120;255m';
        $dimGreen = self::ESC . '[38;2;60;160;80m';
        $dimRed = self::ESC . '[38;2;180;60;60m';
        $bold = self::ESC . '[1m';
        $dimBg = self::ESC . '[48;5;236m';

        $steps = [
            // User message
            fn () => $this->typeOut(
                "\n{$red}  ⟡ {$white}Refactor the UserService to use repository pattern and add caching{$r}\n",
                12000
            ),

            // Thinking
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n" .
                "{$dim}  │{$r} Analyzing the codebase to understand the current UserService\n" .
                "{$dim}  │{$r} implementation, identify dependencies, and plan the refactor.\n" .
                "{$dim}  └ {$dim}(2.1s){$r}\n",
                8000
            ),

            // Tool: search
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Search{$r} {$dim}── finding relevant files{$r}\n" .
                "{$dim}  │{$r} {$dim}Pattern:{$r} class UserService\n" .
                "{$dim}  │{$r} {$dim}Found 3 matches:{$r}\n" .
                "{$dim}  │{$r}   {$blue}app/Services/UserService.php{$r}{$dim}:12{$r}  — class UserService\n" .
                "{$dim}  │{$r}   {$blue}app/Http/Controllers/UserController.php{$r}{$dim}:8{$r}  — use UserService\n" .
                "{$dim}  │{$r}   {$blue}tests/Unit/UserServiceTest.php{$r}{$dim}:15{$r}  — class UserServiceTest\n" .
                "{$dim}  └ {$dim}(0.3s){$r}\n",
                6000
            ),

            // Tool: read file
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Read{$r} {$blue}app/Services/UserService.php{$r}\n" .
                "{$dim}  │{$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "{$dim}  │  {$gray} 1{$r}  {$dimBg} <?php{$r}\n" .
                "{$dim}  │  {$gray} 2{$r}  {$dimBg} {$r}\n" .
                "{$dim}  │  {$gray} 3{$r}  {$dimBg} {$magenta}namespace{$r}{$dimBg} App\\Services;{$r}\n" .
                "{$dim}  │  {$gray} 4{$r}  {$dimBg} {$r}\n" .
                "{$dim}  │  {$gray} 5{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} App\\Models\\User;{$r}\n" .
                "{$dim}  │  {$gray} 6{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} Illuminate\\Support\\Facades\\DB;{$r}\n" .
                "{$dim}  │  {$gray} 7{$r}  {$dimBg} {$r}\n" .
                "{$dim}  │  {$gray} 8{$r}  {$dimBg} {$magenta}class{$r}{$dimBg} {$yellow}UserService{$r}\n" .
                "{$dim}  │  {$gray} 9{$r}  {$dimBg} {{$r}\n" .
                "{$dim}  │  {$gray}10{$r}  {$dimBg}     {$magenta}public function{$r}{$dimBg} {$cyan}getById{$r}{$dimBg}({$magenta}int{$r}{$dimBg} \$id): ?User{$r}\n" .
                "{$dim}  │  {$gray}11{$r}  {$dimBg}     {{$r}\n" .
                "{$dim}  │  {$gray}12{$r}  {$dimBg}         {$magenta}return{$r}{$dimBg} User::find(\$id);{$r}\n" .
                "{$dim}  │  {$gray}13{$r}  {$dimBg}     }{$r}\n" .
                "{$dim}  │  {$gray}14{$r}  {$dimBg} }{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$dim}14 lines{$r}\n",
                8000
            ),

            // Thinking again
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n" .
                "{$dim}  │{$r} The service directly queries Eloquent. I'll extract a\n" .
                "{$dim}  │{$r} UserRepositoryInterface, create an EloquentUserRepository,\n" .
                "{$dim}  │{$r} and add a caching decorator using Laravel's Cache facade.\n" .
                "{$dim}  └ {$dim}(1.8s){$r}\n",
                8000
            ),

            // Tool: create file (interface)
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$green}◈ Write{$r} {$blue}app/Repositories/UserRepositoryInterface.php{$r} {$dim}(new){$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  │  {$dimGreen}+ <?php{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ namespace App\\Repositories;{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ use App\\Models\\User;{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ interface UserRepositoryInterface{$r}\n" .
                "{$dim}  │  {$dimGreen}+ {{$r}\n" .
                "{$dim}  │  {$dimGreen}+     public function find(int \$id): ?User;{$r}\n" .
                "{$dim}  │  {$dimGreen}+     public function findByEmail(string \$email): ?User;{$r}\n" .
                "{$dim}  │  {$dimGreen}+     public function save(User \$user): User;{$r}\n" .
                "{$dim}  │  {$dimGreen}+     public function delete(int \$id): bool;{$r}\n" .
                "{$dim}  │  {$dimGreen}+ }{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$green}✓ Created{$r}\n",
                4000
            ),

            // Tool: create file (repository)
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$green}◈ Write{$r} {$blue}app/Repositories/EloquentUserRepository.php{$r} {$dim}(new){$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  │  {$dimGreen}+ <?php{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ namespace App\\Repositories;{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ use App\\Models\\User;{$r}\n" .
                "{$dim}  │  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$dimGreen}+ class EloquentUserRepository implements UserRepositoryInterface{$r}\n" .
                "{$dim}  │  {$dimGreen}+ {{$r}\n" .
                "{$dim}  │  {$dimGreen}+     public function find(int \$id): ?User{$r}\n" .
                "{$dim}  │  {$dimGreen}+     {{$r}\n" .
                "{$dim}  │  {$dimGreen}+         return User::find(\$id);{$r}\n" .
                "{$dim}  │  {$dimGreen}+     }{$r}\n" .
                "{$dim}  │  {$dim}... +32 more lines{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$green}✓ Created{$r}\n",
                4000
            ),

            // Tool: edit file (refactor UserService)
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}◈ Edit{$r} {$blue}app/Services/UserService.php{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  │  {$gray} 5{$r}  {$dimRed}- use Illuminate\\Support\\Facades\\DB;{$r}\n" .
                "{$dim}  │  {$gray} 5{$r}  {$dimGreen}+ use App\\Repositories\\UserRepositoryInterface;{$r}\n" .
                "{$dim}  │  {$gray} 6{$r}  {$dimGreen}+ use Illuminate\\Support\\Facades\\Cache;{$r}\n" .
                "{$dim}  │  {$gray}  {$r}\n" .
                "{$dim}  │  {$gray}10{$r}  {$dimRed}-     public function getById(int \$id): ?User{$r}\n" .
                "{$dim}  │  {$gray}10{$r}  {$dimGreen}+     public function __construct({$r}\n" .
                "{$dim}  │  {$gray}11{$r}  {$dimGreen}+         private UserRepositoryInterface \$repository{$r}\n" .
                "{$dim}  │  {$gray}12{$r}  {$dimGreen}+     ) {}{$r}\n" .
                "{$dim}  │  {$gray}13{$r}  {$dimGreen}+{$r}\n" .
                "{$dim}  │  {$gray}14{$r}  {$dimGreen}+     public function getById(int \$id): ?User{$r}\n" .
                "{$dim}  │  {$gray}  {$r}\n" .
                "{$dim}  │  {$gray}12{$r}  {$dimRed}-         return User::find(\$id);{$r}\n" .
                "{$dim}  │  {$gray}16{$r}  {$dimGreen}+         return Cache::remember(\"user.{\$id}\", 3600, function () use (\$id) {{$r}\n" .
                "{$dim}  │  {$gray}17{$r}  {$dimGreen}+             return \$this->repository->find(\$id);{$r}\n" .
                "{$dim}  │  {$gray}18{$r}  {$dimGreen}+         });{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$green}✓ Saved{$r} {$dim}(-2, +9 lines){$r}\n",
                3000
            ),

            // Tool: run tests
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Bash{$r} {$dim}php artisan test --filter=UserService{$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "{$dim}  │{$r}\n" .
                "{$dim}  │{$r}   {$green}PASS{$r}  Tests\\Unit\\UserServiceTest\n" .
                "{$dim}  │{$r}   {$green}✓{$r} it returns a user by id {$dim}(0.04s){$r}\n" .
                "{$dim}  │{$r}   {$green}✓{$r} it caches the user after first fetch {$dim}(0.02s){$r}\n" .
                "{$dim}  │{$r}   {$green}✓{$r} it invalidates cache on user update {$dim}(0.03s){$r}\n" .
                "{$dim}  │{$r}   {$green}✓{$r} it delegates to repository for persistence {$dim}(0.01s){$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  │{$r}   Tests:    {$bold}{$green}4 passed{$r} {$dim}(4 assertions){$r}\n" .
                "{$dim}  │{$r}   Duration: {$dim}0.31s{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$green}✓ Exit code 0{$r}\n",
                5000
            ),

            // Summary
            fn () => $this->typeOut(
                "\n{$dim}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n\n" .
                "  {$white}Done.{$r} Refactored UserService to repository pattern with caching.\n\n" .
                "  {$dim}Files changed:{$r}\n" .
                "    {$green}+{$r} app/Repositories/UserRepositoryInterface.php {$dim}(new){$r}\n" .
                "    {$green}+{$r} app/Repositories/EloquentUserRepository.php {$dim}(new){$r}\n" .
                "    {$yellow}~{$r} app/Services/UserService.php {$dim}(-2, +9){$r}\n" .
                "    {$yellow}~{$r} app/Providers/AppServiceProvider.php {$dim}(+3){$r}\n\n" .
                "  {$dim}Tokens: 1,847 in · 923 out · cost: \$0.024{$r}\n\n",
                6000
            ),
        ];

        foreach ($steps as $step) {
            $step();
            usleep(300000);
        }
    }

    private function typeOut(string $text, int $charDelay): void
    {
        foreach (mb_str_split($text) as $char) {
            echo $char;
            if ($char !== "\n" && $char !== ' ') {
                usleep($charDelay);
            }
        }
    }

    private function renderStatic(): void
    {
        $reset = self::ESC . '[0m';
        $red = self::ESC . '[38;2;255;60;40m';
        $dimRed = self::ESC . '[38;2;160;30;30m';
        $gold = self::ESC . '[38;2;255;200;80m';
        $dim = self::ESC . '[38;5;245m';
        $white = self::ESC . '[1;37m';

        $border = $dimRed . '  ⟡ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ ⟡' . $reset;
        $side = $dimRed . '  ┃' . $reset;
        $sideR = $dimRed . '┃' . $reset;

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
            [$r, $g, $b] = $gradients[$i];
            $color = self::ESC . "[38;2;{$r};{$g};{$b}m";
            echo $side . '  ' . $color . $line . $reset . str_repeat(' ', max(0, 93 - mb_strwidth($line))) . $sideR . "\n";
        }
        echo $side . str_repeat(' ', 95) . $sideR . "\n";
        echo $border . "\n\n";
        echo '                      ' . $gold . '⚡ Κοσμοκράτωρ — Ruler of the Cosmos ⚡' . $reset . "\n\n";
        echo '                 ☿  ♀  ♁  ♂  ♃  ♄  ♅  ♆  ✦  ☽  ☉  ★  ✧  ⊛  ◈' . "\n\n";
        echo '                        ' . $dim . 'Your AI coding agent by ' . $white . 'OpenCompany' . $reset . "\n\n";
    }
}
