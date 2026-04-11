<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Diff\DiffRenderer;
use Kosmokrator\UI\Highlight\Lua\LuaLanguage;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\ToolRendererInterface;
use Kosmokrator\UI\Tui\ExplorationClassifier;
use Tempest\Highlight\Highlighter;

/**
 * ANSI fallback implementation of tool call/result display and permission prompts.
 */
final class AnsiToolRenderer implements ToolRendererInterface
{
    private ?DiffRenderer $diffRenderer = null;

    private ?Highlighter $highlighter = null;

    private array $lastToolArgs = [];

    private ?TaskStore $taskStore = null;

    private float $executingStartTime = 0.0;

    /** @var array<int, array{name: string, args: array, output: ?string, success: ?bool}> */
    private array $discoveryBatch = [];

    private bool $discoveryBatchOpen = false;

    /** @var \Closure(): void */
    private \Closure $flushQuestionRecapCallback;

    public function __construct(\Closure $flushQuestionRecapCallback)
    {
        $this->flushQuestionRecapCallback = $flushQuestionRecapCallback;
    }

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function setLastToolArgs(array $args): void
    {
        $this->lastToolArgs = $args;
    }

    public function getLastToolArgs(): array
    {
        return $this->lastToolArgs;
    }

    public function showToolCall(string $name, array $args): void
    {
        // Skip flush during active discovery batch — the batch manages its own output
        if (! in_array($name, ['ask_user', 'ask_choice'], true) && ! $this->discoveryBatchOpen) {
            ($this->flushQuestionRecapCallback)();
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $gold = Theme::accent();
        $icon = Theme::toolIcon($name);

        $this->lastToolArgs = $args;
        $friendly = Theme::toolLabel($name);
        $border = Theme::borderTask();

        // Discovery batch: accumulate consecutive omens (read-only) tools
        if (ExplorationClassifier::isOmensTool($name, $args)) {
            if (! $this->discoveryBatchOpen) {
                $this->discoveryBatchOpen = true;
                echo "\n{$border}  ┌ {$gold}☽ Reading the omens...{$r}\n";
            }
            $this->discoveryBatch[] = ['name' => $name, 'args' => $args, 'output' => null, 'success' => null];

            return;
        }

        // Non-omens tool: finalize any open discovery batch before rendering
        $this->finalizeDiscoveryBatch();

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

        // Lua execution: show full code block with syntax highlighting and line numbers
        if ($name === 'execute_lua' && isset($args['code'])) {
            $code = $args['code'];
            $r = Theme::reset();
            $text = Theme::text();
            $dim = Theme::dim();

            $highlighted = $this->highlightLuaCode($code);
            $lines = explode("\n", $highlighted);
            $lineCount = count($lines);
            $numWidth = strlen((string) $lineCount);

            echo "\n{$border}  ┃ {$gold}{$icon} {$friendly}{$r}  {$dim}{$lineCount} lines{$r}\n";
            foreach ($lines as $i => $line) {
                $num = str_pad((string) ($i + 1), $numWidth, ' ', STR_PAD_LEFT);
                echo "{$border}  ┃{$r} {$dim}{$num}{$r}  {$line}{$r}\n";
            }

            return;
        }

        // Lua doc tools: compact single-line
        if (in_array($name, ['lua_list_docs', 'lua_search_docs', 'lua_read_doc'], true)) {
            echo "\n{$border}  ┃ {$gold}{$icon} {$friendly}{$r}";
            foreach ($args as $key => $value) {
                $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                echo " {$dim}{$key}:{$r} {$display}";
            }
            echo "\n";

            return;
        }

        // Bash: compact inline header — icon + truncated command on one line
        if ($name === 'bash') {
            $command = $this->stripCwdPrefix(trim((string) ($args['command'] ?? '')));
            $maxCmdLen = 110;
            if ($command === '') {
                $command = '(shell)';
            } elseif (mb_strlen($command) > $maxCmdLen) {
                $command = mb_substr($command, 0, $maxCmdLen - 1).'…';
            }
            echo "\n{$border}  ┃ {$gold}{$icon}{$r} {$dim}{$command}{$r}\n";

            return;
        }

        $skipKeys = ['content', 'old_string', 'new_string'];

        echo "\n{$border}  ┃ {$gold}{$icon} {$friendly}{$r}";
        foreach ($args as $key => $value) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
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
        // Skip flush during active discovery batch — the batch manages its own output
        if (! in_array($name, ['ask_user', 'ask_choice'], true) && ! $this->discoveryBatchOpen) {
            ($this->flushQuestionRecapCallback)();
        }

        // Discovery batch: fill in the result for the pending entry
        if ($this->discoveryBatchOpen && $this->discoveryBatch !== []) {
            $last = &$this->discoveryBatch[count($this->discoveryBatch) - 1];
            if ($last['output'] === null && $last['name'] === $name) {
                $last['output'] = $output;
                $last['success'] = $success;
                unset($last);
                $this->echoDiscoveryResultLine($name, $this->discoveryBatch[count($this->discoveryBatch) - 1]);

                return;
            }
            unset($last);
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

        // Lua execution: show full output
        if ($name === 'execute_lua') {
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r}\n";
            foreach (explode("\n", $output) as $line) {
                echo "{$border}  ┃{$r} {$text}{$line}{$r}\n";
            }

            return;
        }

        // Lua doc tools: compact result
        if (in_array($name, ['lua_list_docs', 'lua_search_docs', 'lua_read_doc'], true)) {
            $lineCount = count(explode("\n", $output));
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r} {$dim}({$lineCount} lines){$r}\n";

            return;
        }

        // File read: just show status
        if ($name === 'file_read') {
            $lineCount = count(explode("\n", $output));
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r} {$dim}({$lineCount} lines){$r}\n";

            return;
        }

        // File write: compact creation notice
        if ($name === 'file_write') {
            $path = Theme::relativePath((string) ($this->lastToolArgs['path'] ?? ''));
            $content = (string) ($this->lastToolArgs['content'] ?? $output);
            $lineCount = substr_count($content, "\n") + 1;
            echo "{$border}  ┃ {$status} {$dim}Created{$r} {$path} {$dim}({$lineCount} lines){$r}\n";

            return;
        }

        // Apply patch: compact summary
        if ($name === 'apply_patch') {
            echo "{$border}  ┃ {$status} {$dim}{$friendly}{$r} {$dim}{$output}{$r}\n";

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

        // Bash: compact output — 2 preview lines on success, full output on failure
        if ($name === 'bash') {
            $outputLines = explode("\n", $output);
            $maxPreview = $success ? 2 : PHP_INT_MAX;

            if ($outputLines === ['']) {
                $label = $success ? '(no output)' : Theme::error().'command failed'.$r;
                echo "{$border}  ┃ {$status}{$r} {$dim}{$label}{$r}\n";

                return;
            }

            foreach (array_slice($outputLines, 0, $maxPreview) as $line) {
                echo "{$border}  ┃{$r} {$text}{$line}{$r}\n";
            }

            $remaining = count($outputLines) - $maxPreview;
            if ($remaining > 0) {
                echo "{$border}  ┃ {$dim}⊛ +{$remaining} lines{$r}\n";
            }

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
        $border = Theme::borderTask();
        $icon = Theme::toolIcon($toolName);
        $friendly = Theme::toolLabel($toolName);

        $context = $this->formatPermissionContext($toolName, $args);

        echo "{$border}  ┌ {$yellow}{$icon} {$friendly}{$r}\n";
        if ($context !== '') {
            echo "{$border}  │{$r} {$context}\n";
        }
        echo "{$border}  │{$r}\n";

        while (true) {
            $answer = readline("{$border}  └ {$yellow}Allow?{$r} {$dim}[Y]es / [a]lways / [g]uardian / [p]rometheus / [n]o ▸{$r} ");

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

    /** No-op: auto-approve status is visible via the permission label in the status bar. */
    public function showAutoApproveIndicator(string $toolName): void
    {
        // Intentionally silent — auto-approve is already visible in the status bar
    }

    public function showToolExecuting(string $name): void
    {
        if ($this->isTaskTool($name) || in_array($name, ['ask_user', 'ask_choice', 'subagent'], true)) {
            return;
        }
        $this->executingStartTime = microtime(true);
        $dim = Theme::dim();
        $r = Theme::reset();
        $border = Theme::borderTask();
        echo "{$border}  ┃ {$dim}running...{$r}\r";
    }

    public function updateToolExecuting(string $output): void
    {
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
            $elapsed = $this->executingStartTime > 0 ? (int) (microtime(true) - $this->executingStartTime) : 0;
            $elapsedStr = $elapsed > 0 ? " {$dim}({$elapsed}s){$r}" : '';
            $maxPreview = 80 - ($elapsed > 0 ? strlen("({$elapsed}s) ") : 0);
            $preview = mb_strlen($last) > $maxPreview ? mb_substr($last, 0, $maxPreview).'…' : $last;
            echo "\r\033[2K{$border}  ┃ {$dim}{$preview}{$elapsedStr}{$r}\r";
        }
    }

    public function clearToolExecuting(): void
    {
        echo "\r\033[2K"; // Clear the running line
    }

    /** Closes any open discovery batch, printing the summary footer and resetting state. */
    public function finalizeDiscoveryBatch(): void
    {
        if (! $this->discoveryBatchOpen) {
            return;
        }

        $r = Theme::reset();
        $border = Theme::borderTask();
        $dim = Theme::dim();

        $summary = $this->formatDiscoverySummary();
        echo "{$border}  └ {$dim}{$summary}{$r}\n";

        $this->discoveryBatch = [];
        $this->discoveryBatchOpen = false;

        // Flush any pending question recaps that were deferred during the batch
        ($this->flushQuestionRecapCallback)();
    }

    /** Prints a single compact result line inside the discovery batch. */
    private function echoDiscoveryResultLine(string $name, array $entry): void
    {
        $r = Theme::reset();
        $border = Theme::borderTask();
        $dim = Theme::dim();
        $status = $entry['success'] ? Theme::success().'✓' : Theme::error().'✗';
        $args = $entry['args'];
        $output = (string) $entry['output'];

        $label = match ($name) {
            'file_read' => Theme::relativePath((string) ($args['path'] ?? ''))
                ." {$dim}(".count(explode("\n", $output)).' lines)',
            'glob' => ($args['pattern'] ?? '?')
                ." {$dim}(".count(array_filter(explode("\n", $output), fn (string $l) => trim($l) !== '')).' matches)',
            'grep' => '"'.mb_substr((string) ($args['pattern'] ?? ''), 0, 40).'"'
                ." {$dim}(".count(array_filter(explode("\n", $output), fn (string $l) => trim($l) !== '')).' matches)',
            'memory_search' => '"'.mb_substr((string) ($args['query'] ?? $args['type'] ?? ''), 0, 40).'"',
            'bash' => $this->stripCwdPrefix(mb_substr(trim((string) ($args['command'] ?? '')), 0, 60)),
            default => Theme::toolLabel($name),
        };

        echo "{$border}  │ {$status}{$r} {$label}{$r}\n";
    }

    /** Summarizes the discovery batch as "3 reads · 2 globs · 1 search". */
    private function formatDiscoverySummary(): string
    {
        $counts = [];
        foreach ($this->discoveryBatch as $entry) {
            $label = match ($entry['name']) {
                'file_read' => 'read',
                'glob' => 'glob',
                'grep' => 'search',
                'memory_search' => 'recall',
                'bash' => 'probe',
                default => $entry['name'],
            };
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = $count.' '.$label.($count > 1 ? 's' : '');
        }

        return implode(' · ', $parts);
    }

    /** Checks if a tool name is a task management tool. */
    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    private function buildDiffLines(string $old, string $new, string $path): array
    {
        return $this->getDiffRenderer()->renderLines($old, $new, $path);
    }

    private function getDiffRenderer(): DiffRenderer
    {
        return $this->diffRenderer ??= new DiffRenderer;
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

    /** Extracts the primary context line for a permission prompt. */
    private function formatPermissionContext(string $toolName, array $args): string
    {
        $dim = Theme::dim();
        $r = Theme::reset();

        return match ($toolName) {
            'bash' => $this->stripCwdPrefix(trim((string) ($args['command'] ?? ''))),
            'shell_start' => trim((string) ($args['command'] ?? '')),
            'shell_write' => "{$dim}input:{$r} ".trim((string) ($args['input'] ?? '')),
            'file_write', 'file_read' => Theme::relativePath((string) ($args['path'] ?? '')),
            'file_edit' => Theme::relativePath((string) ($args['path'] ?? '')),
            'apply_patch' => $this->countPatchFiles((string) ($args['patch'] ?? '')).' file(s)',
            'execute_lua' => substr_count((string) ($args['code'] ?? ''), "\n") + 1 .' lines of Lua',
            default => '',
        };
    }

    /** Count files mentioned in a patch block. */
    private function countPatchFiles(string $patch): int
    {
        return max(1, preg_match_all('/^(Add|Update|Delete|Move) File:/m', $patch));
    }

    /**
     * Strip leading `cd /absolute/path && ` prefix from a bash command for display.
     */
    private function stripCwdPrefix(string $command): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $command;
        }

        $prefix = "cd {$cwd} && ";
        if (str_starts_with($command, $prefix)) {
            return substr($command, strlen($prefix));
        }

        foreach (['"', "'"] as $quote) {
            $quotedPrefix = "cd {$quote}{$cwd}{$quote} && ";
            if (str_starts_with($command, $quotedPrefix)) {
                return substr($command, strlen($quotedPrefix));
            }
        }

        return $command;
    }

    /**
     * Highlight Lua code using tempest/highlight with our Lua language definition.
     */
    private function highlightLuaCode(string $code): string
    {
        try {
            return $this->getHighlighter()->parse($code, new LuaLanguage);
        } catch (\Throwable $e) {
            $r = Theme::reset();
            $text = Theme::text();

            return implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $code)));
        }
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme);
    }
}
