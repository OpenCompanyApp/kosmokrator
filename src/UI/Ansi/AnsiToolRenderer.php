<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Diff\DiffRenderer;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\ToolRendererInterface;

/**
 * ANSI fallback implementation of tool call/result display and permission prompts.
 */
final class AnsiToolRenderer implements ToolRendererInterface
{
    private ?DiffRenderer $diffRenderer = null;

    private array $lastToolArgs = [];

    private ?TaskStore $taskStore = null;

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
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            ($this->flushQuestionRecapCallback)();
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
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            ($this->flushQuestionRecapCallback)();
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
            $preview = mb_strlen($last) > 80 ? mb_substr($last, 0, 80).'…' : $last;
            echo "\r{$border}  ┃ {$dim}{$preview}{$r}\r";
        }
    }

    public function clearToolExecuting(): void
    {
        echo "\r\033[2K"; // Clear the running line
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
}
