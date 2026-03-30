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
    private string $currentModeLabel = 'Edit';
    private string $currentPermissionLabel = 'Guardian ◈';

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

        // Task tools: compact display, suppress noise
        if ($this->isTaskTool($name)) {
            $label = $this->formatTaskToolCallLabel($name, $args, $icon, $friendly, $dim, $r);
            if ($label !== null) {
                echo "{$border}  ┃ {$gold}{$label}{$r}\n";
            }

            return;
        }

        $skipKeys = ['content', 'old_string', 'new_string'];

        echo "\n{$border}  ┃ {$gold}{$icon} {$friendly}{$r}";
        foreach ($args as $key => $value) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            $display = is_string($value) ? $value : json_encode($value);
            if ($key === 'path' || $key === 'file_path') {
                $display = Theme::relativePath($display);
            }
            if (mb_strlen($display) > 100) {
                $display = mb_substr($display, 0, 100) . '…';
            }
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
            $answer = readline("{$yellow}  ⟡ Allow?{$r} {$dim}[Y]es / [a]lways / [g]uardian / [p]rometheus / [n]o ▸{$r} ");

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

            if ($char === 'g') {
                return 'guardian';
            }

            if ($char === 'p') {
                return 'prometheus';
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

    public function clearConversation(): void
    {
        // ANSI renderer prints directly to stdout, no widget tree to clear
    }

    public function replayHistory(array $messages): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $border = Theme::rgb(128, 100, 40);

        foreach ($messages as $msg) {
            if ($msg instanceof \Prism\Prism\ValueObjects\Messages\UserMessage) {
                $text = mb_substr(trim(str_replace("\n", ' ', $msg->content)), 0, 120);
                echo "\n  {$white}⟡ {$text}{$r}\n";
            } elseif ($msg instanceof \Prism\Prism\ValueObjects\Messages\AssistantMessage) {
                if ($msg->content !== '') {
                    $preview = mb_substr(trim(str_replace("\n", ' ', $msg->content)), 0, 120);
                    if (mb_strlen($msg->content) > 120) {
                        $preview .= '…';
                    }
                    echo "  {$dim}{$preview}{$r}\n";
                }
            }
        }
        echo "\n";
    }

    public function showNotice(string $message): void
    {
        $r = Theme::reset();
        $yellow = Theme::warning();
        echo "\n{$yellow}  {$message}{$r}\n\n";
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->currentModeLabel = $label;
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->currentPermissionLabel = $label;
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        $dim = Theme::dim();
        $r = Theme::reset();
        echo "{$dim}  \u{2713} auto{$r}\n";
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

        $permPart = in_array($this->currentModeLabel, ['Plan', 'Ask'])
            ? '' : " {$dim}{$this->currentPermissionLabel} ·";

        echo "{$dim}  {$this->currentModeLabel} ·{$permPart} {$model} · {$bar} {$dim}· {$costLabel}{$r}\n\n";
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

    public function playPrometheus(): void
    {
        $prometheus = new AnsiPrometheus();
        $prometheus->animate();
    }

    public function showWelcome(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $text = Theme::text();
        $white = Theme::white();
        $gold = Theme::accent();
        $border = Theme::primaryDim();
        $orbit = Theme::rgb(60, 50, 70);
        $sun = Theme::rgb(255, 220, 80);
        $mercury = Theme::rgb(180, 180, 200);
        $venus = Theme::rgb(255, 180, 100);
        $earth = Theme::rgb(80, 160, 255);
        $mars = Theme::rgb(255, 80, 60);
        $jupiter = Theme::rgb(255, 200, 130);
        $saturn = Theme::rgb(210, 180, 140);
        $uranus = Theme::rgb(130, 210, 230);
        $neptune = Theme::rgb(70, 100, 220);
        $ring = Theme::rgb(80, 70, 90);
        $ringDim = Theme::rgb(50, 45, 60);

        // Orrery — concentric planetary orbits
        echo "\n";
        echo "                    {$ringDim}·  ·  ·  {$uranus}♅{$r}  {$ringDim}·  ·  ·{$r}\n";
        echo "                {$orbit}·{$r}        {$ring}·{$r} {$earth}♁{$r} {$ring}·{$r}        {$orbit}·{$r}\n";
        echo "             {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$mercury}☿{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}\n";
        echo "           {$saturn}♄{$r}   {$ring}·{$r}         {$sun}☉{$r}         {$ring}·{$r}   {$jupiter}♃{$r}\n";
        echo "             {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$venus}♀{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}\n";
        echo "                {$orbit}·{$r}        {$ring}·{$r} {$mars}♂{$r} {$ring}·{$r}        {$orbit}·{$r}\n";
        echo "                    {$ringDim}·  ·  ·  {$neptune}♆{$r}  {$ringDim}·  ·  ·{$r}\n";
        echo "\n";

        $green = Theme::rgb(80, 200, 120);
        $purple = Theme::rgb(160, 120, 255);
        $orange = Theme::rgb(255, 180, 60);
        $silver = Theme::rgb(180, 180, 200);
        $steel = Theme::rgb(100, 140, 200);
        $cyan = Theme::rgb(100, 200, 200);

        // Quick reference
        echo "  {$gold}Quick Reference{$r}\n";
        echo "  {$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n";
        echo "  {$green}/edit{$dim}  {$purple}/plan{$dim}  {$orange}/ask{$r}               {$dim}Agent mode (write / read-only / Q&A){$r}\n";
        echo "  {$silver}/guardian{$dim}  {$steel}/argus{$dim}  {$gold}/prometheus{$r}    {$dim}Permission mode (smart / strict / auto){$r}\n";
        echo "  {$cyan}/compact{$dim}  {$cyan}/new{$dim}  {$cyan}/resume{$r}           {$dim}Context and session management{$r}\n";
        $muted = Theme::rgb(160, 160, 170);
        echo "  {$muted}/settings{$dim}  {$muted}/memories{$dim}  {$muted}/sessions{$r}   {$dim}Configuration and persistence{$r}\n";
        echo "  {$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n";
        echo "\n";
        echo "  {$text}Type a message to begin. Press {$white}Ctrl+C{$text} to exit.{$r}\n\n";
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

    /**
     * Format task tool call label. Returns null to suppress output entirely.
     */
    private function formatTaskToolCallLabel(string $name, array $args, string $icon, string $friendly, string $dim, string $r): ?string
    {
        $white = Theme::white();

        if ($name === 'task_create') {
            if (isset($args['tasks']) && $args['tasks'] !== '') {
                $items = json_decode($args['tasks'], true);
                if (is_array($items)) {
                    return "{$icon} {$friendly} {$dim}created " . count($items) . " tasks{$r}";
                }
            }
            $subject = $args['subject'] ?? '';

            return "{$icon} {$friendly} {$white}{$subject}{$r}";
        }

        if ($name === 'task_update') {
            $status = $args['status'] ?? '';
            if ($status === 'in_progress') {
                return null;
            }
            $id = $args['id'] ?? '';
            $task = $this->taskStore?->get($id);
            $subject = $task?->subject ?? $id;
            $statusIcon = match ($status) {
                'completed' => "\033[38;2;80;220;100m\u{25CF}{$r}",
                'cancelled' => "\033[38;2;255;80;60m\u{2717}{$r}",
                default => '',
            };

            return "{$icon} {$friendly} {$statusIcon} {$white}{$subject}{$r}";
        }

        // task_get, task_list: silent
        return null;
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
