<?php

namespace Kosmokrator\UI\Ansi;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\Diff\DiffRenderer;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Theme;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AnsiRenderer implements RendererInterface
{
    private readonly AnsiIntro $intro;

    private string $streamBuffer = '';

    private ?MarkdownToAnsi $markdownRenderer = null;

    private ?DiffRenderer $diffRenderer = null;

    private array $lastToolArgs = [];

    private ?TaskStore $taskStore = null;

    private string $currentModeLabel = 'Edit';

    private string $currentPermissionLabel = 'Guardian ◈';

    private bool $wasActive = false;

    /** @var array<array{question: string, answer: string, answered: bool, recommended: bool}> */
    private array $pendingQuestionRecap = [];

    public function __construct()
    {
        $this->intro = new AnsiIntro;
    }

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function refreshTaskBar(): void
    {
        // ANSI: task bar is rendered fresh on each prompt() call, no explicit refresh needed
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
        $this->flushPendingQuestionRecap();
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
        $this->flushPendingQuestionRecap();
        // No-op: readline already displays the typed input
    }

    public function setPhase(AgentPhase $phase): void
    {
        if ($phase === AgentPhase::Thinking || $phase === AgentPhase::Tools) {
            $this->wasActive = true;
        }

        // ANSI mode: only Thinking has a visual indicator (static text)
        if ($phase === AgentPhase::Thinking) {
            $this->showThinking();
        }

        if ($phase === AgentPhase::Idle && $this->wasActive) {
            $this->wasActive = false;
            TerminalNotification::notify();
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
        $this->flushPendingQuestionRecap();
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
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->flushPendingQuestionRecap();
        }

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
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->flushPendingQuestionRecap();
        }

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

    /**
     * @return string[]
     */
    private function buildDiffLines(string $old, string $new, string $path): array
    {
        return $this->getDiffRenderer()->renderLines($old, $new, $path);
    }

    private function getDiffRenderer(): DiffRenderer
    {
        return $this->diffRenderer ??= new DiffRenderer;
    }

    public function clearConversation(): void
    {
        // ANSI renderer prints directly to stdout, no widget tree to clear
        $this->pendingQuestionRecap = [];
    }

    public function replayHistory(array $messages): void
    {
        $this->pendingQuestionRecap = [];
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
                $this->flushPendingQuestionRecap();
                echo "\n  {$white}⟡ {$msg->content}{$r}\n";

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                if ($msg->content !== '') {
                    $this->flushPendingQuestionRecap();
                    if (str_contains($msg->content, "\x1b[")) {
                        echo "\n".$msg->content.$r."\n";
                    } else {
                        echo $this->getMarkdownRenderer()->render($msg->content);
                    }
                }

                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = $toolCall->arguments();
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;

                    if ($name === 'ask_user') {
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result))
                            : '';
                        $trimmed = trim($answer);

                        $this->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $trimmed,
                            answered: $trimmed !== '',
                        );

                        continue;
                    }

                    if ($name === 'ask_choice') {
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result))
                            : 'dismissed';
                        $selected = $this->findChoiceFromArgs($args, $answer);

                        $this->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $answer === 'dismissed' ? '' : $answer,
                            answered: $answer !== 'dismissed',
                            recommended: (bool) ($selected['recommended'] ?? false),
                        );

                        continue;
                    }

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
                    $this->flushPendingQuestionRecap();
                    $this->lastToolArgs = $args;
                    $this->showToolCall($name, $args);

                    // Render paired result immediately after
                    if ($toolResult !== null) {
                        $this->lastToolArgs = $toolResult->args;
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result);
                        $this->showToolResult($name, $output, true);
                    }
                }

                continue;
            }
        }
        $this->flushPendingQuestionRecap();
        echo "\n";
    }

    public function showNotice(string $message): void
    {
        $this->flushPendingQuestionRecap();
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

    public function showToolExecuting(string $name): void
    {
        // ANSI mode: show a simple "running..." indicator
        if ($this->isTaskTool($name) || in_array($name, ['ask_user', 'ask_choice', 'subagent'], true)) {
            return;
        }
        $dim = Theme::dim();
        $r = Theme::reset();
        $border = Theme::borderTask();
        echo "{$border}  ┃ {$dim}running...{$r}\r";
    }

    public function updateToolExecuting(string $output): void
    {
        // ANSI mode: show last line of output
        $lines = explode("\n", trim($output));
        $last = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) !== '') {
                $last = trim($lines[$i]);
                break;
            }
        }
        if ($last !== '') {
            $dim = Theme::dim();
            $r = Theme::reset();
            $border = Theme::borderTask();
            $preview = mb_strlen($last) > 80 ? mb_substr($last, 0, 80).'…' : $last;
            echo "\r{$border}  ┃ {$dim}{$preview}{$r}\r";
        }
    }

    public function clearToolExecuting(): void
    {
        echo "\r\033[2K"; // Clear the running line
    }

    public function consumeQueuedMessage(): ?string
    {
        return null; // ANSI mode is synchronous, no queuing
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        // No-op: ANSI mode is synchronous, immediate commands not supported
    }

    public function showError(string $message): void
    {
        $this->flushPendingQuestionRecap();
        $r = Theme::reset();
        $err = Theme::error();
        echo "\n{$err}  ✗ Error: {$message}{$r}\n\n";
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->flushPendingQuestionRecap();
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
        $answer = readline('> ') ?: '';
        $trimmed = trim($answer);

        $this->queueQuestionRecap(
            question: $question,
            answer: $trimmed,
            answered: $trimmed !== '',
        );

        return $answer;
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
            $choice = $choices[$pick - 1];
            $this->queueQuestionRecap(
                question: $question,
                answer: $choice['label'],
                answered: true,
                recommended: (bool) ($choice['recommended'] ?? false),
            );

            return $choice['label'];
        }

        $this->queueQuestionRecap(
            question: $question,
            answer: '',
            answered: false,
        );

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
                'retrying' => "{$gold}↻{$r}",
                default => "{$dim}○{$r}",
            };

            $meta = ucfirst($s->agentType)." \"{$task}\"";

            $detail = match ($s->status) {
                'done' => " · {$s->toolCalls} tools · ".Theme::formatTokenCount($s->tokensIn + $s->tokensOut).' tokens',
                'running' => " · {$s->toolCalls} tools · running",
                'waiting' => ' · waiting on '.implode(', ', $s->dependsOn),
                'queued' => $s->group !== null ? " · queued (group: {$s->group})" : ' · queued',
                'queued_global' => ' · queued (concurrency limit)',
                'retrying' => " · retry #{$s->retries} · {$s->toolCalls} tools",
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

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        echo $this->formatDashboard($summary, $allStats);
    }

    private function formatDashboard(array $s, array $allStats): string
    {
        $r = Theme::reset();
        $border = Theme::primaryDim();
        $gold = Theme::accent();
        $green = Theme::success();
        $red = Theme::error();
        $cyan = Theme::info();
        $white = Theme::white();
        $dim = Theme::dim();
        $text = Theme::text();
        $w = 72;

        $out = '';
        $hr = str_repeat('─', $w);
        $strip = fn (string $s): string => preg_replace('/\033\[[^m]*m/', '', $s);
        $pad = function (string $content) use ($r, $border, $w, $strip): string {
            $visible = mb_strlen($strip($content));
            $gap = max(0, $w - $visible);

            return "{$border}  │{$r} {$content}".str_repeat(' ', $gap)." {$border}│{$r}\n";
        };
        $blank = "{$border}  │{$r}".str_repeat(' ', $w + 2)."{$border}│{$r}\n";

        // ┌ Top border ┐
        $out .= "\n{$border}  ┌{$hr}┐{$r}\n";
        $out .= $blank;

        // Title
        $out .= $pad("{$gold}⏺  S W A R M   C O N T R O L{$r}");
        $out .= $blank;

        // Progress bar
        $pct = $s['total'] > 0 ? $s['done'] / $s['total'] : 0;
        $barWidth = 40;
        $filled = (int) round($pct * $barWidth);
        $empty = $barWidth - $filled;
        $barColor = $pct < 0.5 ? $green : $gold;
        $pctStr = number_format($pct * 100, 1).'%';
        $out .= $pad("{$barColor}".str_repeat('█', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$white}{$pctStr}{$r}");
        $out .= $pad("{$text}{$s['done']} of {$s['total']} agents completed{$r}");
        $out .= $blank;

        // Status counts
        $d = (string) $s['done'];
        $ru = (string) $s['running'];
        $q = (string) $s['queued'];
        $f = (string) $s['failed'];
        $out .= $pad("{$green}✓ {$d} done{$r}   {$gold}● {$ru} running{$r}   {$cyan}◌ {$q} queued{$r}   {$red}✗ {$f} failed{$r}");
        $out .= $blank;

        // ├─ Resources ─┤
        $sectionHdr = function (string $icon, string $label) use ($border, $gold, $r, $w): string {
            $labelLen = mb_strlen($icon) + 1 + strlen($label);
            $fill = max(0, $w - $labelLen - 6);

            return "{$border}  ├──── {$gold}{$icon} {$label}{$r} ".str_repeat("{$border}─", $fill)."{$border}┤{$r}\n";
        };

        $out .= $sectionHdr('☉', 'Resources');
        $out .= $blank;

        $tokIn = Theme::formatTokenCount($s['tokensIn']);
        $tokOut = Theme::formatTokenCount($s['tokensOut']);
        $tokTotal = Theme::formatTokenCount($s['tokensIn'] + $s['tokensOut']);
        $out .= $pad("{$dim}Tokens    {$white}{$tokIn} in{$dim}  ·  {$white}{$tokOut} out{$dim}  ·  {$white}{$tokTotal} total{$r}");

        $cost = Theme::formatCost($s['cost']);
        $avgCost = Theme::formatCost($s['avgCost']);
        $out .= $pad("{$dim}Cost      {$white}{$cost}{$dim}   ·  avg {$white}{$avgCost}{$dim}/agent{$r}");

        $elapsed = AgentDisplayFormatter::formatElapsed($s['elapsed']);
        $rate = $s['rate'] > 0 ? number_format($s['rate'], 1).' agents/min' : 'N/A';
        $out .= $pad("{$dim}Elapsed   {$white}{$elapsed}{$dim}  ·  rate {$white}{$rate}{$r}");

        if ($s['eta'] > 0) {
            $eta = '~'.AgentDisplayFormatter::formatElapsed($s['eta']).' remaining';
            $out .= $pad("{$dim}ETA       {$gold}{$eta}{$r}");
        }
        $out .= $blank;

        // ├─ Active ─┤
        if ($s['running'] > 0 || $s['retrying'] > 0) {
            $ac = $s['running'] + $s['retrying'];
            $out .= $sectionHdr('●', "Active ({$ac})");
            $out .= $blank;

            $shown = 0;
            foreach ($s['active'] as $agent) {
                if ($shown >= 8) {
                    $remaining = count($s['active']) - 8;
                    $out .= $pad("{$dim}… {$remaining} more agents running{$r}");
                    break;
                }
                $out .= $pad($this->dashFormatAgentLine($agent));
                $shown++;
            }
            $out .= $blank;
        }

        // ├─ Failures ─┤
        if ($s['failed'] > 0) {
            $out .= $sectionHdr('✗', "Failures ({$s['failed']})");
            $out .= $blank;

            if ($s['retriedAndRecovered'] > 0) {
                $permanent = $s['failed'] - $s['retriedAndRecovered'];
                $out .= $pad("{$dim}{$s['retriedAndRecovered']} recovered via retry  ·  {$permanent} permanent{$r}");
            }

            $shown = 0;
            foreach ($s['failures'] as $agent) {
                if ($shown >= 5) {
                    $remaining = count($s['failures']) - 5;
                    $out .= $pad("{$dim}… {$remaining} more failures{$r}");
                    break;
                }
                $error = mb_substr($agent->error ?? 'unknown', 0, 30);
                $retryTag = $agent->retries > 0 ? "  {$red}exhausted{$r}" : '';
                $type = str_pad(ucfirst($agent->agentType), 8);
                $task = str_pad(mb_substr($agent->task, 0, 22), 22);
                $out .= $pad("{$red}✗{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$dim}{$error}{$r}{$retryTag}");
                $shown++;
            }
            $out .= $blank;
        }

        // ├─ By Type ─┤
        if (count($s['byType']) > 1) {
            $out .= $sectionHdr('◈', 'By Type');
            $out .= $blank;

            $totalTokens = $s['tokensIn'] + $s['tokensOut'];
            foreach ($s['byType'] as $type => $t) {
                $typeName = str_pad(ucfirst($type), 10);
                $typeCost = ($s['cost'] > 0 && $totalTokens > 0)
                    ? Theme::formatCost($s['cost'] * ($t['tokensIn'] + $t['tokensOut']) / $totalTokens)
                    : Theme::formatCost(0.0);
                $out .= $pad("{$cyan}{$typeName}{$r} {$green}{$t['done']} done{$r}  ·  {$gold}{$t['running']} running{$r}  ·  {$dim}{$t['queued']} queued{$r}  ·  {$white}{$typeCost}{$r}");
            }
            $out .= $blank;
        }

        // └ Bottom border ┘
        $out .= "{$border}  └{$hr}┘{$r}\n\n";

        return $out;
    }

    private function dashFormatAgentLine(SubagentStats $agent): string
    {
        $r = Theme::reset();
        $gold = Theme::accent();
        $dim = Theme::dim();
        $text = Theme::text();

        $icon = $agent->status === 'retrying' ? '↻' : '●';
        $type = str_pad(ucfirst($agent->agentType), 8);
        $task = str_pad(mb_substr($agent->task, 0, 22), 22);

        $barWidth = 16;
        $ratio = min($agent->elapsed() / 120.0, 1.0);
        $filled = (int) round($ratio * $barWidth);
        $empty = $barWidth - $filled;

        $elapsed = str_pad((int) $agent->elapsed().'s', 5);
        $tools = $agent->toolCalls.' tool'.($agent->toolCalls !== 1 ? 's' : '');
        $retryNote = $agent->status === 'retrying' ? "  retry #{$agent->retries}" : '';

        return "{$gold}{$icon}{$r} {$dim}{$type}{$r}  {$text}{$task}{$r}  {$gold}".str_repeat('━', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$dim}{$elapsed} {$tools}{$retryNote}{$r}";
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
        echo "  {$muted}/settings{$dim}  {$muted}/memories{$dim}  {$muted}/sessions{$dim}  {$muted}/agents{$r}  {$dim}Configuration and monitoring{$r}\n";
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
        $types = AgentDisplayFormatter::summarizeAgentTypes($entries);
        $isBg = ($entries[0]['args']['mode'] ?? 'await') === 'background';
        $bgTag = $isBg ? " {$dim}(background){$r}" : '';

        // Single agent: compact one-liner
        if ($count === 1) {
            $e = $entries[0];
            [$label, $typeColor] = AgentDisplayFormatter::formatAgentLabel($e['args']);
            echo "\n{$border}  {$cyan}⏺{$r} {$label}{$bgTag}\n";

            return;
        }

        // Multiple agents: tree
        echo "\n{$border}  {$cyan}⏺ {$count} agents{$r}{$bgTag}\n";

        $last = $count - 1;
        foreach ($entries as $i => $entry) {
            $connector = $i === $last ? '└─' : '├─';
            [$label, $typeColor] = AgentDisplayFormatter::formatAgentLabel($entry['args']);
            $coord = AgentDisplayFormatter::formatCoordinationTags($entry['args']);

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

        // Filter out background acks — show remaining (failures, awaited results)
        $entries = array_values(array_filter($entries, fn ($e) => ! str_contains($e['result'], 'spawned in background')));
        if (empty($entries)) {
            return;
        }

        $count = count($entries);
        $succeeded = count(array_filter($entries, fn ($e) => $e['success']));
        $failed = $count - $succeeded;
        $types = AgentDisplayFormatter::summarizeAgentTypes($entries);

        // Single agent: compact
        if ($count === 1) {
            $e = $entries[0];
            $icon = $e['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            [$label, $_] = AgentDisplayFormatter::formatAgentLabel($e['args']);
            $stats = AgentDisplayFormatter::formatAgentStats($e);
            $preview = AgentDisplayFormatter::extractResultPreview($e['result']);
            $children = $e['children'] ?? [];

            echo "\n{$border}  {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo AgentDisplayFormatter::renderChildTree($children, "{$border}     ");
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
            [$label, $_] = AgentDisplayFormatter::formatAgentLabel($entry['args']);
            $stats = AgentDisplayFormatter::formatAgentStats($entry);
            $preview = AgentDisplayFormatter::extractResultPreview($entry['result']);
            $children = $entry['children'] ?? [];

            echo "{$border}  {$connector} {$icon} {$label}{$stats}\n";
            if ($children !== []) {
                echo AgentDisplayFormatter::renderChildTree($children, "{$border}  {$continuation}  ");
            }
            if ($preview !== '') {
                echo "{$border}  {$continuation}  {$dim}⎿ {$preview}{$r}\n";
            }
        }
    }

    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    private function queueQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->pendingQuestionRecap[] = [
            'question' => $question,
            'answer' => $answer,
            'answered' => $answered,
            'recommended' => $answered && $recommended,
        ];
    }

    private function flushPendingQuestionRecap(): void
    {
        if ($this->pendingQuestionRecap === []) {
            return;
        }

        $r = Theme::reset();
        $accent = Theme::accent();
        $white = Theme::white();
        $answerColor = Theme::info();
        $dim = Theme::dim();

        $answeredCount = count(array_filter($this->pendingQuestionRecap, static fn (array $entry): bool => $entry['answered']));
        echo "\n{$accent}› •{$r} {$dim}Questions {$answeredCount}/".count($this->pendingQuestionRecap)." answered{$r}\n";

        foreach ($this->pendingQuestionRecap as $index => $entry) {
            if ($index > 0) {
                echo "\n";
            }

            foreach ($this->wrapWithPrefix($entry['question'], '    • ', '      ', 100) as $line) {
                echo "{$white}{$line}{$r}\n";
            }

            $answer = $entry['answered']
                ? $entry['answer'].($entry['recommended'] ? ' (Recommended)' : '')
                : '(dismissed)';
            $color = $entry['answered'] ? $answerColor : $dim;

            foreach ($this->wrapWithPrefix($answer, '      ', '      ', 100) as $line) {
                echo "{$color}{$line}{$r}\n";
            }
        }

        $this->pendingQuestionRecap = [];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, detail: string|null, recommended?: bool}|null
     */
    private function findChoiceFromArgs(array $args, string $label): ?array
    {
        $raw = json_decode((string) ($args['choices'] ?? '[]'), true);
        if (! is_array($raw)) {
            return null;
        }

        $choices = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $choices[] = ['label' => $item, 'detail' => null, 'recommended' => false];

                continue;
            }

            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }

            $choices[] = [
                'label' => (string) $item['label'],
                'detail' => isset($item['detail']) ? (string) $item['detail'] : null,
                'recommended' => (bool) ($item['recommended'] ?? false),
            ];
        }

        foreach ($choices as $choice) {
            if ($choice['label'] === $label) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function wrapWithPrefix(string $text, string $firstPrefix, string $restPrefix, int $width): array
    {
        $wrapped = [];
        $current = '';
        $words = preg_split('/\s+/', trim($text)) ?: [];

        foreach ($words as $word) {
            $prefix = $current === '' && $wrapped === [] ? $firstPrefix : ($current === '' ? $restPrefix : '');
            $lineWidth = max(10, $width - mb_strwidth($prefix));
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if (mb_strwidth($candidate) > $lineWidth) {
                if ($current !== '') {
                    $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;
                    $current = $word;

                    continue;
                }

                $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).mb_substr($word, 0, $lineWidth);
                $current = mb_substr($word, $lineWidth);

                continue;
            }

            $current = $candidate;
        }

        if ($current === '') {
            return [($wrapped === [] ? $firstPrefix : $restPrefix)];
        }

        $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;

        return $wrapped;
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
            $subject = $task->subject ?? $id;
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
