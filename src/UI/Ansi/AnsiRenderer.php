<?php

namespace Kosmokrator\UI\Ansi;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
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
        $this->intro = new AnsiIntro;
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

        $input = readline($red.'  ⟡ '.$r);

        if ($input === false) {
            return '/quit';
        }

        return trim($input);
    }

    public function showUserMessage(string $text): void
    {
        // No-op: readline already displays the typed input
    }

    public function setPhase(AgentPhase $phase): void
    {
        // ANSI mode: only Thinking has a visual indicator (static text)
        if ($phase === AgentPhase::Thinking) {
            $this->showThinking();
        }
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

    public function showCompacting(): void
    {
        $r = Theme::reset();
        $red = Theme::rgb(208, 64, 64);
        echo "\n{$red}  ⧫ Compacting context...{$r}\n";
    }

    public function clearCompacting(): void
    {
        // No-op for ANSI — static text
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
                echo "\n".$this->streamBuffer.Theme::reset()."\n";
            } else {
                $rendered = $this->getMarkdownRenderer()->render($this->streamBuffer);
                echo "\n".$rendered;
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
        $border = Theme::borderTask();

        // Task tools: compact display, suppress noise
        if ($this->isTaskTool($name)) {
            $label = $this->formatTaskToolCallLabel($name, $args, $icon, $friendly, $dim, $r);
            if ($label !== null) {
                echo "{$border}  ┃ {$gold}{$label}{$r}\n";
            }

            return;
        }

        // Ask tools: silent — the question is shown by the tool's UI method
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            return;
        }

        // Subagent: handled by showSubagentSpawn/showSubagentBatch — skip individual display
        if ($name === 'subagent') {
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
                $display = mb_substr($display, 0, 100).'…';
            }
            echo "\n{$border}  ┃{$r} {$dim}{$key}:{$r} {$display}";
        }
        echo "\n";
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $r = Theme::reset();
        $border = Theme::borderTask();
        $text = Theme::text();
        $dim = Theme::dim();
        $status = $success ? Theme::success().'✓' : Theme::error().'✗';

        $friendly = Theme::toolLabel($name);

        // Task tools: silent — the call line + sticky bar are enough
        if ($this->isTaskTool($name)) {
            return;
        }

        // Ask tools: silent result — the user already saw their own answer
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            return;
        }

        // Subagent: handled by showSubagentBatch — skip individual display
        if ($name === 'subagent') {
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
                echo "{$border}  ┃ {$dim}⊛ +".(count($diffLines) - $maxLines)." more lines{$r}\n";
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
            echo "{$border}  ┃ {$dim}⊛ +".(count($lines) - $maxLines)." more lines{$r}\n";
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
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme);
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
        $gold = Theme::accent();
        $border = Theme::borderTask();

        // Index tool results by toolCallId for pairing
        $resultsByCallId = [];
        foreach ($messages as $msg) {
            if ($msg instanceof ToolResultMessage) {
                foreach ($msg->toolResults as $toolResult) {
                    $resultsByCallId[$toolResult->toolCallId] = $toolResult;
                }
            }
        }

        foreach ($messages as $msg) {
            if ($msg instanceof SystemMessage
                || $msg instanceof ToolResultMessage) {
                continue;
            }

            if ($msg instanceof UserMessage) {
                echo "\n  {$white}⟡ {$msg->content}{$r}\n";

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                if ($msg->content !== '') {
                    if (str_contains($msg->content, "\x1b[")) {
                        echo "\n".$msg->content.$r."\n";
                    } else {
                        echo $this->getMarkdownRenderer()->render($msg->content);
                    }
                }

                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = $toolCall->arguments();

                    if ($this->isTaskTool($name)) {
                        if ($name === 'task_create') {
                            $icon = Theme::toolIcon($name);
                            $friendly = Theme::toolLabel($name);
                            $label = $this->formatTaskToolCallLabel($name, $args, $icon, $friendly, $dim, $r);
                            if ($label !== null) {
                                echo "{$border}  ┃ {$gold}{$label}{$r}\n";
                            }
                        }

                        continue;
                    }

                    // Render tool call
                    $this->lastToolArgs = $args;
                    $this->showToolCall($name, $args);

                    // Render paired result immediately after
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;
                    if ($toolResult !== null) {
                        $this->lastToolArgs = $toolResult->args;
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result);
                        $this->showToolResult($name, $output, true);
                    }
                }

                continue;
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
        // Intentionally silent — auto-approve is already visible in the status bar
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

    public function approvePlan(string $currentPermissionMode): ?array
    {
        // ANSI fallback: no interactive dialog, user types manually
        return null;
    }

    public function askUser(string $question): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        echo "\n{$accent}?{$r} {$question}\n";

        return readline('> ') ?: '';
    }

    public function askChoice(string $question, array $choices): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        $dim = Theme::dim();

        echo "\n{$accent}?{$r} {$question}\n";
        foreach ($choices as $i => $choice) {
            echo "  {$accent}".($i + 1).".{$r} {$choice['label']}\n";
            if ($choice['detail'] !== null) {
                echo "{$dim}{$choice['detail']}{$r}\n";
            }
        }
        echo "  {$dim}".(count($choices) + 1).". Dismiss{$r}\n";

        $pick = (int) readline("{$dim}>{$r} ");
        if ($pick >= 1 && $pick <= count($choices)) {
            return $choices[$pick - 1]['label'];
        }

        return 'dismissed';
    }

    public function showSubagentStatus(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $r = "\033[0m";
        $dim = "\033[38;5;243m";
        $green = "\033[38;2;80;200;120m";
        $gold = "\033[38;2;218;165;32m";
        $red = "\033[38;2;255;100;100m";
        $blue = "\033[38;2;100;149;237m";
        $border = "\033[38;5;240m";

        $running = count(array_filter($stats, fn ($s) => $s->status === 'running'));
        $done = count(array_filter($stats, fn ($s) => $s->status === 'done'));
        $total = count($stats);

        echo "\n{$border}  ┌ {$gold}{$running} running, {$done}/{$total} finished{$r}\n";

        $items = array_values($stats);
        $last = count($items) - 1;

        foreach ($items as $i => $s) {
            $connector = $i === $last ? '└─' : '├─';
            $task = mb_substr($s->task, 0, 50);

            $statusIcon = match ($s->status) {
                'done' => "{$green}✓{$r}",
                'running' => "{$gold}●{$r}",
                'failed' => "{$red}✗{$r}",
                'waiting' => "{$blue}◌{$r}",
                default => "{$dim}○{$r}",
            };

            $meta = ucfirst($s->agentType)." \"{$task}\"";

            $detail = match ($s->status) {
                'done' => " · {$s->toolCalls} tools · ".$this->formatTokenCount($s->tokensIn + $s->tokensOut).' tokens',
                'running' => " · {$s->toolCalls} tools · running",
                'waiting' => ' · waiting on '.implode(', ', $s->dependsOn),
                'queued' => $s->group !== null ? " · queued (group: {$s->group})" : ' · queued',
                'failed' => ' · failed: '.mb_substr($s->error ?? '', 0, 40),
                default => '',
            };

            echo "{$border}  {$connector} {$statusIcon} {$dim}{$meta}{$detail}{$r}\n";
        }
    }

    public function clearSubagentStatus(): void
    {
        // ANSI mode: status is printed inline, nothing to clear
    }

    public function teardown(): void
    {
        echo Theme::showCursor();
    }

    public function playTheogony(): void
    {
        $theogony = new AnsiTheogony;
        $theogony->animate();
    }

    public function playPrometheus(): void
    {
        $prometheus = new AnsiPrometheus;
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
        echo "  {$cyan}/compact{$dim}  {$cyan}/new{$dim}  {$cyan}/resume{$dim}  {$cyan}/tasks clear{$r}  {$dim}Context and session management{$r}\n";
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
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n".
                "{$dim}  │{$r} Analyzing the codebase to understand the current UserService\n".
                "{$dim}  │{$r} implementation, identify dependencies, and plan the refactor.\n".
                "{$dim}  └ {$dim}(2.1s){$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Search{$r} {$dim}── finding relevant files{$r}\n".
                "{$dim}  │{$r} {$dim}Pattern:{$r} class UserService\n".
                "{$dim}  │{$r} {$dim}Found 3 matches:{$r}\n".
                "{$dim}  │{$r}   {$blue}app/Services/UserService.php{$r}{$dim}:12{$r}  — class UserService\n".
                "{$dim}  │{$r}   {$blue}app/Http/Controllers/UserController.php{$r}{$dim}:8{$r}  — use UserService\n".
                "{$dim}  │{$r}   {$blue}tests/Unit/UserServiceTest.php{$r}{$dim}:15{$r}  — class UserServiceTest\n".
                "{$dim}  └ {$dim}(0.3s){$r}\n",
                6000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Read{$r} {$blue}app/Services/UserService.php{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$gray} 1{$r}  {$dimBg} <?php{$r}\n".
                "{$dim}  │  {$gray} 2{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 3{$r}  {$dimBg} {$magenta}namespace{$r}{$dimBg} App\\Services;{$r}\n".
                "{$dim}  │  {$gray} 4{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} App\\Models\\User;{$r}\n".
                "{$dim}  │  {$gray} 6{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} Illuminate\\Support\\Facades\\DB;{$r}\n".
                "{$dim}  │  {$gray} 7{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 8{$r}  {$dimBg} {$magenta}class{$r}{$dimBg} {$yellow}UserService{$r}\n".
                "{$dim}  │  {$gray} 9{$r}  {$dimBg} {{$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimBg}     {$magenta}public function{$r}{$dimBg} {$cyan}getById{$r}{$dimBg}({$magenta}int{$r}{$dimBg} \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}11{$r}  {$dimBg}     {{$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimBg}         {$magenta}return{$r}{$dimBg} User::find(\$id);{$r}\n".
                "{$dim}  │  {$gray}13{$r}  {$dimBg}     }{$r}\n".
                "{$dim}  │  {$gray}14{$r}  {$dimBg} }{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$dim}14 lines{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n".
                "{$dim}  │{$r} The service directly queries Eloquent. I'll extract a\n".
                "{$dim}  │{$r} UserRepositoryInterface, create an EloquentUserRepository,\n".
                "{$dim}  │{$r} and add a caching decorator using Laravel's Cache facade.\n".
                "{$dim}  └ {$dim}(1.8s){$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$green}◈ Write{$r} {$blue}app/Repositories/UserRepositoryInterface.php{$r} {$dim}(new){$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$dimGreen}+ <?php{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ namespace App\\Repositories;{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ use App\\Models\\User;{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ interface UserRepositoryInterface{$r}\n".
                "{$dim}  │  {$dimGreen}+ {{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function find(int \$id): ?User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function findByEmail(string \$email): ?User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function save(User \$user): User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function delete(int \$id): bool;{$r}\n".
                "{$dim}  │  {$dimGreen}+ }{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Created{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}◈ Edit{$r} {$blue}app/Services/UserService.php{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimRed}- use Illuminate\\Support\\Facades\\DB;{$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimGreen}+ use App\\Repositories\\UserRepositoryInterface;{$r}\n".
                "{$dim}  │  {$gray} 6{$r}  {$dimGreen}+ use Illuminate\\Support\\Facades\\Cache;{$r}\n".
                "{$dim}  │  {$gray}  {$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimRed}-     public function getById(int \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimGreen}+     public function __construct({$r}\n".
                "{$dim}  │  {$gray}11{$r}  {$dimGreen}+         private UserRepositoryInterface \$repository{$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimGreen}+     ) {}{$r}\n".
                "{$dim}  │  {$gray}13{$r}  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$gray}14{$r}  {$dimGreen}+     public function getById(int \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}  {$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimRed}-         return User::find(\$id);{$r}\n".
                "{$dim}  │  {$gray}16{$r}  {$dimGreen}+         return Cache::remember(\"user.{\$id}\", 3600, function () use (\$id) {{$r}\n".
                "{$dim}  │  {$gray}17{$r}  {$dimGreen}+             return \$this->repository->find(\$id);{$r}\n".
                "{$dim}  │  {$gray}18{$r}  {$dimGreen}+         });{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Saved{$r} {$dim}(-2, +9 lines){$r}\n",
                3000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Bash{$r} {$dim}php artisan test --filter=UserService{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │{$r}   {$green}PASS{$r}  Tests\\Unit\\UserServiceTest\n".
                "{$dim}  │{$r}   {$green}✓{$r} it returns a user by id {$dim}(0.04s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it caches the user after first fetch {$dim}(0.02s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it invalidates cache on user update {$dim}(0.03s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it delegates to repository for persistence {$dim}(0.01s){$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │{$r}   Tests:    {$bold}{$green}4 passed{$r} {$dim}(4 assertions){$r}\n".
                "{$dim}  │{$r}   Duration: {$dim}0.31s{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Exit code 0{$r}\n",
                5000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n\n".
                "  {$white}Done.{$r} Refactored UserService to repository pattern with caching.\n\n".
                "  {$dim}Files changed:{$r}\n".
                "    {$green}+{$r} app/Repositories/UserRepositoryInterface.php {$dim}(new){$r}\n".
                "    {$green}+{$r} app/Repositories/EloquentUserRepository.php {$dim}(new){$r}\n".
                "    {$yellow}~{$r} app/Services/UserService.php {$dim}(-2, +9){$r}\n".
                "    {$yellow}~{$r} app/Providers/AppServiceProvider.php {$dim}(+3){$r}\n\n".
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
        return $this->markdownRenderer ??= new MarkdownToAnsi;
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

    public function showSubagentRunning(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $border = Theme::borderTask();
        $count = count($entries);
        $label = $count === 1 ? 'Running...' : "{$count} agents running...";

        echo "{$border}  {$dim}⎿ {$label}{$r}\n";
    }

    public function showSubagentSpawn(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $cyan = "\033[38;2;100;200;220m";
        $border = Theme::borderTask();

        $count = count($entries);
        $types = $this->summarizeAgentTypes($entries);
        $isBg = ($entries[0]['args']['mode'] ?? 'await') === 'background';
        $bgTag = $isBg ? " {$dim}(background){$r}" : '';

        // Single agent: compact one-liner
        if ($count === 1) {
            $e = $entries[0];
            [$label, $typeColor] = $this->formatAgentLabel($e['args'], 'spawn');
            echo "\n{$border}  {$cyan}⏺{$r} {$label}{$bgTag}\n";

            return;
        }

        // Multiple agents: tree
        echo "\n{$border}  {$cyan}⏺ {$count} {$types}{$r}{$bgTag}\n";

        $last = $count - 1;
        foreach ($entries as $i => $entry) {
            $connector = $i === $last ? '└─' : '├─';
            [$label, $typeColor] = $this->formatAgentLabel($entry['args'], 'spawn');
            $coord = $this->formatCoordinationTags($entry['args'], $dim, $r);

            echo "{$border}  {$connector} {$typeColor}●{$r} {$label}{$coord}\n";
        }
    }

    public function showSubagentBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();
        $cyan = "\033[38;2;100;200;220m";
        $border = Theme::borderTask();

        $count = count($entries);
        $succeeded = count(array_filter($entries, fn ($e) => $e['success']));
        $failed = $count - $succeeded;
        $types = $this->summarizeAgentTypes($entries);
        $allBg = ! empty(array_filter($entries, fn ($e) => str_contains($e['result'], 'spawned in background')));

        // Background ack — don't show a second block (spawn block is enough)
        if ($allBg) {
            return;
        }

        // Single agent: compact
        if ($count === 1) {
            $e = $entries[0];
            $icon = $e['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            [$label, $_] = $this->formatAgentLabel($e['args'], 'result');
            $stats = $this->formatAgentStats($e);
            $preview = $this->extractResultPreview($e['result']);
            $children = $e['children'] ?? [];

            echo "\n{$border}  {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo $this->renderChildTree($children, "{$border}     ");
            }
            if ($preview !== '') {
                echo "{$border}     {$dim}⎿ {$preview}{$r}\n";
            }

            return;
        }

        // Multiple agents: tree
        $failSuffix = $failed > 0 ? " {$red}({$failed} failed){$r}" : '';
        echo "\n{$border}  {$green}✓{$r} {$succeeded}/{$count} {$types} finished{$failSuffix}\n";

        $last = $count - 1;
        foreach ($entries as $i => $entry) {
            $connector = $i === $last ? '└─' : '├─';
            $continuation = $i === $last ? '  ' : '│ ';
            $icon = $entry['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            [$label, $_] = $this->formatAgentLabel($entry['args'], 'result');
            $stats = $this->formatAgentStats($entry);
            $preview = $this->extractResultPreview($entry['result']);
            $children = $entry['children'] ?? [];

            echo "{$border}  {$connector} {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo $this->renderChildTree($children, "{$border}  {$continuation}  ");
            }
            if ($preview !== '') {
                echo "{$border}  {$continuation}  {$dim}⎿ {$preview}{$r}\n";
            }
        }
    }

    /**
     * Format agent label: "id · task preview" or "Type · task preview".
     *
     * @return array{string, string} [formatted label, type color]
     */
    private function formatAgentLabel(array $args, string $context): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $type = ucfirst((string) ($args['type'] ?? 'explore'));
        $id = isset($args['id']) && $args['id'] !== '' ? (string) $args['id'] : null;
        $task = (string) ($args['task'] ?? '');
        $taskPreview = mb_strlen($task) > 50 ? mb_substr($task, 0, 50).'...' : $task;

        $typeColor = match (strtolower($type)) {
            'general' => "\033[38;2;218;165;32m",
            'plan' => "\033[38;2;160;120;255m",
            default => "\033[38;2;100;200;220m",
        };

        // Primary label: use ID if set, otherwise type
        $primary = $id !== null
            ? "{$typeColor}{$type}{$r} {$id}"
            : "{$typeColor}{$type}{$r}";

        return ["{$primary} {$dim}· {$taskPreview}{$r}", $typeColor];
    }

    /**
     * Format coordination tags (depends_on, group).
     */
    private function formatCoordinationTags(array $args, string $dim, string $r): string
    {
        $parts = [];
        $dependsOn = $args['depends_on'] ?? [];
        $group = isset($args['group']) && $args['group'] !== '' ? (string) $args['group'] : null;

        if (is_array($dependsOn) && $dependsOn !== []) {
            $parts[] = 'depends on: '.implode(', ', $dependsOn);
        }
        if ($group !== null) {
            $parts[] = "group: {$group}";
        }

        if ($parts === []) {
            return '';
        }

        return " {$dim}→ ".implode(' · ', $parts)."{$r}";
    }

    /**
     * Format stats for a completed agent entry.
     */
    private function formatAgentStats(array $entry): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();

        // We don't have direct access to SubagentStats here (only args + result text),
        // but we can parse tool/token info from the result if the orchestrator included it.
        // For now, keep it simple — stats are shown in the background completion notice.
        return '';
    }

    /**
     * Summarize agent types for the group header (e.g., "Explore agents", "2 Explore + 1 General agents").
     */
    private function summarizeAgentTypes(array $entries): string
    {
        $types = [];
        foreach ($entries as $entry) {
            $type = ucfirst((string) ($entry['args']['type'] ?? 'explore'));
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        if (count($types) === 1) {
            $type = array_key_first($types);

            return $type.(count($entries) === 1 ? ' agent' : ' agents');
        }

        $parts = [];
        foreach ($types as $type => $count) {
            $parts[] = "{$count} {$type}";
        }

        return implode(' + ', $parts).' agents';
    }

    /**
     * Render a nested child agent tree with box-drawing indentation.
     */
    private function renderChildTree(array $children, string $indent): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();
        $output = '';

        $last = count($children) - 1;
        foreach ($children as $i => $child) {
            $connector = $i === $last ? '└─' : '├─';
            $continuation = $i === $last ? '   ' : '│  ';
            $icon = $child['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            $type = ucfirst($child['type']);
            $task = mb_strlen($child['task']) > 40 ? mb_substr($child['task'], 0, 40).'…' : $child['task'];
            $elapsed = $child['elapsed'] > 0 ? " {$dim}({$child['elapsed']}s){$r}" : '';

            $output .= "{$indent}{$connector} {$icon} {$dim}{$type}{$r} {$task}{$elapsed}\n";

            if (($child['children'] ?? []) !== []) {
                $output .= $this->renderChildTree($child['children'], "{$indent}{$continuation}");
            }
        }

        return $output;
    }

    /**
     * Extract a short preview from subagent output for the tree display.
     */
    private function extractResultPreview(string $output): string
    {
        $lines = explode("\n", trim($output));

        // Skip empty lines and markdown headers to find first content line
        foreach ($lines as $line) {
            $stripped = trim($line);
            if ($stripped === '' || str_starts_with($stripped, '#') || str_starts_with($stripped, '---')) {
                continue;
            }
            // Strip leading markdown list markers
            $stripped = preg_replace('/^[-*]\s+/', '', $stripped);
            if (mb_strlen($stripped) > 80) {
                return mb_substr($stripped, 0, 80).'...';
            }

            return $stripped;
        }

        return '';
    }

    private function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1).'M';
        }
        if ($tokens >= 1_000) {
            return round($tokens / 1_000, 1).'k';
        }

        return (string) $tokens;
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
                    return "{$icon} {$friendly} {$dim}created ".count($items)." tasks{$r}";
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
        $border = Theme::borderTask();
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
