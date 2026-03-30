<?php

declare(strict_types=1);

namespace Kosmokrator\Task;

class TaskStore
{
    /** @var array<string, Task> */
    private array $tasks = [];

    public function add(Task $task): void
    {
        $this->tasks[$task->id] = $task;
    }

    public function get(string $id): ?Task
    {
        return $this->tasks[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes): ?Task
    {
        $task = $this->tasks[$id] ?? null;
        if ($task === null) {
            return null;
        }

        if (isset($changes['status'])) {
            $task->transitionTo(TaskStatus::from($changes['status']));
        }
        if (array_key_exists('subject', $changes)) {
            $task->subject = $changes['subject'];
        }
        if (array_key_exists('description', $changes)) {
            $task->description = $changes['description'];
        }
        if (array_key_exists('active_form', $changes)) {
            $task->activeForm = $changes['active_form'];
        }
        if (isset($changes['add_blocked_by'])) {
            foreach ($changes['add_blocked_by'] as $blockerId) {
                if (! in_array($blockerId, $task->blockedBy, true)) {
                    $task->blockedBy[] = $blockerId;
                }
                // Maintain inverse relationship
                $blocker = $this->tasks[$blockerId] ?? null;
                if ($blocker !== null && ! in_array($id, $blocker->blocks, true)) {
                    $blocker->blocks[] = $id;
                }
            }
        }
        if (isset($changes['add_blocks'])) {
            foreach ($changes['add_blocks'] as $blockedId) {
                if (! in_array($blockedId, $task->blocks, true)) {
                    $task->blocks[] = $blockedId;
                }
                // Maintain inverse relationship
                $blocked = $this->tasks[$blockedId] ?? null;
                if ($blocked !== null && ! in_array($id, $blocked->blockedBy, true)) {
                    $blocked->blockedBy[] = $id;
                }
            }
        }
        if (isset($changes['metadata'])) {
            $task->metadata = array_merge($task->metadata, $changes['metadata']);
        }

        // Auto-complete parent when all children are terminal
        if (isset($changes['status']) && $task->parentId !== null) {
            $this->maybeCompleteParent($task->parentId);
        }

        return $task;
    }

    /**
     * @return Task[]
     */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /**
     * @return Task[]
     */
    public function roots(): array
    {
        return array_values(array_filter(
            $this->tasks,
            fn (Task $t) => $t->parentId === null,
        ));
    }

    /**
     * @return Task[]
     */
    public function children(string $parentId): array
    {
        return array_values(array_filter(
            $this->tasks,
            fn (Task $t) => $t->parentId === $parentId,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    public function isBlocked(string $id): bool
    {
        $task = $this->tasks[$id] ?? null;
        if ($task === null || $task->blockedBy === []) {
            return false;
        }

        foreach ($task->blockedBy as $blockerId) {
            $blocker = $this->tasks[$blockerId] ?? null;
            if ($blocker !== null && ! $blocker->status->isTerminal()) {
                return true;
            }
        }

        return false;
    }

    public function renderTree(): string
    {
        if ($this->tasks === []) {
            return 'No tasks.';
        }

        $lines = [];
        foreach ($this->roots() as $root) {
            $this->renderNode($root, 0, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string[] $lines
     */
    private function renderNode(Task $task, int $depth, array &$lines): void
    {
        if ($task->status === TaskStatus::Cancelled) {
            return;
        }

        $indent = str_repeat('  ', $depth);
        $line = $indent . $task->toSummary();

        if ($this->isBlocked($task->id)) {
            $line .= ' [blocked]';
        }

        $lines[] = $line;

        foreach ($this->children($task->id) as $child) {
            $this->renderNode($child, $depth + 1, $lines);
        }
    }

    /**
     * Render tree with ANSI colors for terminal display.
     */
    public function renderAnsiTree(?string $inProgressColor = null): string
    {
        if ($this->tasks === []) {
            return '';
        }

        $lines = [];
        foreach ($this->roots() as $root) {
            $this->renderAnsiNode($root, 0, $lines, $inProgressColor);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string[] $lines
     */
    private function renderAnsiNode(Task $task, int $depth, array &$lines, ?string $inProgressColor = null): void
    {
        if ($task->status === TaskStatus::Cancelled) {
            return;
        }

        $r = "\033[0m";
        $dim = "\033[38;5;240m";
        $white = "\033[1;37m";

        $statusColor = match ($task->status) {
            TaskStatus::Pending => "\033[38;5;245m",
            TaskStatus::InProgress => $inProgressColor ?? "\033[38;2;255;200;80m",
            TaskStatus::Completed => "\033[38;2;80;220;100m",
            TaskStatus::Cancelled => "\033[38;2;255;80;60m",
        };

        $indent = str_repeat('  ', $depth);
        $icon = $task->status->icon();
        $subjectText = mb_strlen($task->subject) > 50
            ? mb_substr($task->subject, 0, 47) . '...'
            : $task->subject;
        $subjectColor = match (true) {
            $task->status->isTerminal() => $dim,
            $task->status === TaskStatus::InProgress && $inProgressColor !== null => $inProgressColor,
            default => $white,
        };
        $subject = $subjectColor . $subjectText;

        $line = "{$indent}{$statusColor}{$icon}{$r} {$subject}{$r}";

        $elapsed = $task->elapsed();
        if ($task->status === TaskStatus::InProgress) {
            $line .= $elapsed !== null
                ? " {$dim}({$elapsed}s){$r}"
                : " {$dim}(running...){$r}";
        } elseif ($task->status->isTerminal() && $elapsed !== null) {
            $line .= " {$dim}({$elapsed}s){$r}";
        }

        if ($this->isBlocked($task->id)) {
            $line .= " {$dim}[blocked]{$r}";
        }

        $lines[] = $line;

        foreach ($this->children($task->id) as $child) {
            $this->renderAnsiNode($child, $depth + 1, $lines, $inProgressColor);
        }
    }

    /**
     * Remove all completed tasks (and their completed children).
     * Keeps pending and in-progress tasks intact.
     */
    /**
     * Remove all terminal (completed/cancelled) tasks.
     * Keeps pending and in-progress tasks intact.
     */
    public function clearTerminal(): void
    {
        foreach ($this->tasks as $id => $task) {
            if ($task->status->isTerminal()) {
                unset($this->tasks[$id]);
            }
        }
    }

    /**
     * Remove all tasks regardless of status.
     */
    public function clearAll(): void
    {
        $this->tasks = [];
    }

    private function hasActiveChildren(string $taskId): bool
    {
        foreach ($this->children($taskId) as $child) {
            if (! $child->status->isTerminal()) {
                return true;
            }
            if ($this->hasActiveChildren($child->id)) {
                return true;
            }
        }

        return false;
    }

    private function maybeCompleteParent(string $parentId): void
    {
        $parent = $this->tasks[$parentId] ?? null;
        if ($parent === null || $parent->status->isTerminal()) {
            return;
        }

        $children = $this->children($parentId);
        if ($children === []) {
            return;
        }

        foreach ($children as $child) {
            if (! $child->status->isTerminal()) {
                return;
            }
        }

        $parent->transitionTo(TaskStatus::Completed);

        // Recurse: if this parent also has a parent, check that too
        if ($parent->parentId !== null) {
            $this->maybeCompleteParent($parent->parentId);
        }
    }
}
