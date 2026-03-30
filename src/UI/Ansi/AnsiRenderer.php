<?php

namespace Kosmokrator\UI\Ansi;

use Amp\Cancellation;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;
use Tempest\Highlight\Highlighter;

class AnsiRenderer implements RendererInterface
{
    private readonly AnsiIntro $intro;
    private string $streamBuffer = '';
    private ?MarkdownToAnsi $markdownRenderer = null;
    private ?Highlighter $highlighter = null;
    private array $lastToolArgs = [];
    private ?TaskStore $taskStore = null;

    public function __construct()
    {
        $this->intro = new AnsiIntro();
    }

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function initialize(): void
    {
        // Nothing needed for ANSI mode
    }

    public function renderIntro(bool $animated): void
    {
        if ($animated) {
            $this->intro->animate();
        } else {
            $this->intro->renderStatic();
        }
    }

    public function prompt(): string
    {
        $this->echoTaskBar();

        $r = Theme::reset();
        $red = Theme::primary();

        $input = readline($red . '  ⟡ ' . $r);

        if ($input === false) {
            return '/quit';
        }

        return trim($input);
    }

    public function showUserMessage(string $text): void
    {
        // No-op: readline already displays the typed input
    }

    public function showThinking(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $blue = Theme::rgb(112, 160, 208);

        echo "\n{$dim}  ┌ {$blue}⚡ Thinking...{$r}\n";
    }

    public function clearThinking(): void
    {
        // No-op for ANSI — thinking indicator is static text
    }

    public function getCancellation(): ?Cancellation
    {
        return null;
    }

    public function streamChunk(string $text): void
    {
        $this->streamBuffer .= $text;
    }

    public function streamComplete(): void
    {
        if ($this->streamBuffer !== '') {
            if (str_contains($this->streamBuffer, "\x1b[")) {
                // Raw ANSI art — output directly, don't parse as markdown
                echo "\n" . $this->streamBuffer . Theme::reset() . "\n";
            } else {
                $rendered = $this->getMarkdownRenderer()->render($this->streamBuffer);
                echo "\n" . $rendered;
            }
            $this->streamBuffer = '';
        }
    }

    public function showToolCall(string $name, array $args): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $gold = Theme::accent();
        $icon = Theme::toolIcon($name);

        $this->lastToolArgs = $args;
        $friendly = Theme::toolLabel($name);
        $border = Theme::rgb(128, 100, 40);

        // Task tools: clean formatted display (no leading blank line)
        if ($this->isTaskTool($name)) {
            echo "{$border}  ┃ {$gold}{$icon} {$friendly}{$r}";
            $this->echoTaskToolCallArgs($name, $args, $border, $dim, $r);
            echo "\n";

            return;
        }

        $skipKeys = ['content', 'old_string', 'new_string'];

        echo "\n{$border}  ┃ {$gold}{$icon} {$friendly}{$r}";
        foreach ($args as $key => $value) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            $display = is_string($value) ? $value : json_encode($value);
            echo "\n{$border}  ┃{$r} {$dim}{$key}:{$r} {$display}";
        }
        echo "\n";
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $r = Theme::reset();
        $border = Theme::rgb(128, 100, 40);
        $text = Theme::text();
        $dim = Theme::dim();
        $status = $success ? Theme::success() . '✓' : Theme::error() . '✗';

        $friendly = Theme::toolLabel($name);

        // Task tools: silent — the call line + sticky bar are enough
        if ($this->isTaskTool($name)) {
            return;
        }

        // File read: just show status
        if ($name === 'file_read') {
            $lineCount = count(explode("\n", $output));
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r} {$dim}({$lineCount} lines){$r}\n";
            return;
        }

        // File edit: show diff view
        if ($name === 'file_edit' && $success && isset($this->lastToolArgs['old_string'])) {
            $diffLines = $this->buildDiffLines(
                $this->lastToolArgs['old_string'],
                $this->lastToolArgs['new_string'] ?? '',
                $this->lastToolArgs['path'] ?? '',
            );
            $maxLines = 20;
            foreach (array_slice($diffLines, 0, $maxLines) as $line) {
                echo "{$border}  ┃{$r} {$line}{$r}\n";
            }
            if (count($diffLines) > $maxLines) {
                echo "{$border}  ┃ {$dim}⊛ +" . (count($diffLines) - $maxLines) . " more lines{$r}\n";
            }
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r}\n";
            return;
        }

        $lines = explode("\n", $output);
        $maxLines = 20;

        foreach (array_slice($lines, 0, $maxLines) as $line) {
            echo "{$border}  ┃{$r} {$text}{$line}{$r}\n";
        }

        if (count($lines) > $maxLines) {
            echo "{$border}  ┃ {$dim}⊛ +" . (count($lines) - $maxLines) . " more lines{$r}\n";
        }

        echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r}\n";
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        $r = Theme::reset();
        $yellow = Theme::warning();
        $dim = Theme::dim();

        while (true) {
            $answer = readline("{$yellow}  ⟡ Allow?{$r} {$dim}[Y]es / [n]o / [a]lways ▸{$r} ");

            if ($answer === false) {
                return 'deny';
            }

            $char = strtolower(trim($answer));

            if ($char === '' || $char === 'y') {
                return 'allow';
            }

            if ($char === 'n') {
                return 'deny';
            }

            if ($char === 'a') {
                return 'always';
            }
        }
    }

    private function renderFileReadResult(array $lines, int $maxLines): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $gray = Theme::text();

        // Detect language from file extension
        $path = $this->lastToolArgs['path'] ?? '';
        $language = KosmokratorTerminalTheme::detectLanguage($path);

        // Separate line numbers from code content
        $codeLines = [];
        $lineNums = [];
        foreach (array_slice($lines, 0, $maxLines) as $line) {
            if (preg_match('/^(\s*\d+)\t(.*)$/', $line, $m)) {
                $lineNums[] = $m[1];
                $codeLines[] = $m[2];
            } else {
                $lineNums[] = '';
                $codeLines[] = $line;
            }
        }

        // Highlight the code block
        $code = implode("\n", $codeLines);
        if ($language !== '') {
            try {
                $highlighted = $this->getHighlighter()->parse($code, $language);
            } catch (\Throwable) {
                $highlighted = $code;
            }
            $highlightedLines = explode("\n", $highlighted);
        } else {
            $highlightedLines = $codeLines;
        }

        // Output with line numbers
        foreach ($highlightedLines as $i => $hLine) {
            $num = $lineNums[$i] ?? '';
            echo "{$dim}  │{$r} {$gray}{$num}{$r}\t{$hLine}{$r}\n";
        }
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme());
    }

    /**
     * @return string[]
     */
    private function buildDiffLines(string $old, string $new, string $path): array
    {
        $r = Theme::reset();
        $removeFg = Theme::diffRemove();
        $addFg = Theme::diffAdd();
        $removeBg = Theme::diffRemoveBg();
        $addBg = Theme::diffAddBg();

        $language = KosmokratorTerminalTheme::detectLanguage($path);
        $oldCode = ($language !== '') ? $this->tryHighlight($old, $language) : $old;
        $newCode = ($language !== '') ? $this->tryHighlight($new, $language) : $new;

        $result = [];
        foreach (explode("\n", $oldCode) as $line) {
            $result[] = "{$removeBg}{$removeFg} - {$r}{$removeBg} {$line}{$r}";
        }
        foreach (explode("\n", $newCode) as $line) {
            $result[] = "{$addBg}{$addFg} + {$r}{$addBg} {$line}{$r}";
        }

        return $result;
    }

    private function tryHighlight(string $code, string $language): string
    {
        try {
            return $this->getHighlighter()->parse($code, $language);
        } catch (\Throwable) {
            return $code;
        }
    }

    public function showNotice(string $message): void
    {
        $r = Theme::reset();
        $yellow = Theme::warning();
        echo "\n{$yellow}  {$message}{$r}\n\n";
    }

    public function showMode(string $label, string $color = ''): void
    {
        // ANSI mode shows mode in the prompt prefix — no-op here
    }

    public function consumeQueuedMessage(): ?string
    {
        return null; // ANSI mode is synchronous, no queuing
    }

    public function showError(string $message): void
    {
        $r = Theme::reset();
        $err = Theme::error();
        echo "\n{$err}  ✗ Error: {$message}{$r}\n\n";
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $bar = Theme::contextBar($tokensIn, $maxContext);
        $costLabel = Theme::formatCost($cost);

        echo "{$dim}  {$model} · {$bar} {$dim}· {$costLabel}{$r}\n\n";
    }

    public function showSettings(array $currentSettings): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::warning();
        $white = "\033[1;37m";

        echo "\n{$accent}  ⚙ Settings{$r}\n";
        foreach ($currentSettings as $key => $value) {
            echo "{$dim}    {$white}{$key}{$r}{$dim}: {$value}{$r}\n";
        }
        echo "{$dim}  (Interactive settings panel requires TUI mode){$r}\n\n";

        return [];
    }

    public function pickSession(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $white = "\033[1;37m";

        echo "\n{$white}  Select a session:{$r}\n";
        foreach ($items as $i => $item) {
            $num = $i + 1;
            $desc = $item['description'] ?? '';
            echo "{$dim}  [{$num}] {$white}{$item['label']}{$r}  {$dim}{$desc}{$r}\n";
        }
        echo "{$dim}  [0] Cancel{$r}\n";

        $choice = (int) readline('  > ');
        if ($choice < 1 || $choice > count($items)) {
            return null;
        }

        return $items[$choice - 1]['value'];
    }

    public function teardown(): void
    {
        echo Theme::showCursor();
    }

    public function playTheogony(): void
    {
        $theogony = new AnsiTheogony();
        $theogony->animate();
    }

    public function showWelcome(): void
    {
        $r = Theme::reset();
        $dim = Theme::text();
        $white = Theme::white();

        echo "\n";
        echo $dim . '  Type a message to begin. Press ' . $white . 'Ctrl+C' . $dim . ' to exit.' . $r . "\n\n";
    }

    public function seedMockSession(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $gray = Theme::text();
        $white = Theme::white();
        $red = Theme::primary();
        $green = Theme::success();
        $yellow = Theme::warning();
        $cyan = Theme::info();
        $blue = Theme::link();
        $magenta = Theme::code();
        $dimGreen = Theme::diffAdd();
        $dimRed = Theme::diffRemove();
        $bold = Theme::bold();
        $dimBg = Theme::codeBg();

        $steps = [
            fn () => $this->typeOut(
                "\n{$red}  ⟡ {$white}Refactor the UserService to use repository pattern and add caching{$r}\n",
                12000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n" .
                "{$dim}  │{$r} Analyzing the codebase to understand the current UserService\n" .
                "{$dim}  │{$r} implementation, identify dependencies, and plan the refactor.\n" .
                "{$dim}  └ {$dim}(2.1s){$r}\n",
                8000
            ),
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
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Read{$r} {$blue}app/Services/UserService.php{$r}\n" .
                "{$dim}  │{$r}\n" .
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
                "{$dim}  │  {$gray}14{$r}  {$dimBg} }{$r}\n" .
                "{$dim}  │{$r}\n" .
                "{$dim}  └ {$dim}14 lines{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n" .
                "{$dim}  │{$r} The service directly queries Eloquent. I'll extract a\n" .
                "{$dim}  │{$r} UserRepositoryInterface, create an EloquentUserRepository,\n" .
                "{$dim}  │{$r} and add a caching decorator using Laravel's Cache facade.\n" .
                "{$dim}  └ {$dim}(1.8s){$r}\n",
                8000
            ),
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
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Bash{$r} {$dim}php artisan test --filter=UserService{$r}\n" .
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

    private function getMarkdownRenderer(): MarkdownToAnsi
    {
        return $this->markdownRenderer ??= new MarkdownToAnsi();
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

    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    private function echoTaskToolCallArgs(string $name, array $args, string $border, string $dim, string $r): void
    {
        $white = Theme::white();

        if ($name === 'task_create') {
            if (isset($args['tasks']) && $args['tasks'] !== '') {
                // Batch mode — parse and show count + subjects
                $items = json_decode($args['tasks'], true);
                if (is_array($items)) {
                    $count = count($items);
                    echo " {$dim}({$count} tasks){$r}";
                    foreach ($items as $item) {
                        $subject = $item['subject'] ?? '(untitled)';
                        echo "\n{$border}  ┃{$r}    {$dim}+{$r} {$white}{$subject}{$r}";
                    }
                }
            } else {
                // Single mode
                $subject = $args['subject'] ?? '';
                echo " {$white}{$subject}{$r}";
                if (isset($args['parent_id']) && $args['parent_id'] !== '') {
                    echo " {$dim}(child of {$args['parent_id']}){$r}";
                }
            }
        } elseif ($name === 'task_update') {
            $id = $args['id'] ?? '';
            $task = $this->taskStore?->get($id);
            $subject = $task?->subject ?? $id;
            echo " {$white}{$subject}{$r}";

            if (isset($args['status']) && $args['status'] !== '') {
                $statusLabel = match ($args['status']) {
                    'in_progress' => "\033[38;2;255;200;80min progress{$r}",
                    'completed' => "\033[38;2;80;220;100mcompleted{$r}",
                    'cancelled' => "\033[38;2;255;80;60mcancelled{$r}",
                    default => $dim . $args['status'] . $r,
                };
                echo " {$dim}\u{2192}{$r} {$statusLabel}";
            }
        } elseif ($name === 'task_get') {
            $id = $args['id'] ?? '';
            $task = $this->taskStore?->get($id);
            $subject = $task?->subject ?? $id;
            echo " {$white}{$subject}{$r}";
        }
        // task_list: no args to display
    }

    private function echoTaskBar(): void
    {
        if ($this->taskStore === null || $this->taskStore->isEmpty()) {
            return;
        }

        $r = Theme::reset();
        $border = Theme::rgb(128, 100, 40);
        $accent = Theme::accent();

        $tree = $this->taskStore->renderAnsiTree();
        $lines = explode("\n", $tree);

        echo "{$border}  ┌ {$accent}Tasks{$r}\n";
        foreach ($lines as $line) {
            echo "{$border}  │{$r} {$line}{$r}\n";
        }
        echo "{$border}  └{$r}\n";
    }
}
