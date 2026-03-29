<?php

declare(strict_types=1);

namespace Kosmokrator\Task;

class Task
{
    public string $id;

    public string $subject;

    public string $description;

    public TaskStatus $status;

    public ?string $activeForm;

    public ?string $parentId;

    /** @var string[] */
    public array $blockedBy;

    /** @var string[] */
    public array $blocks;

    /** @var array<string, mixed> */
    public array $metadata;

    public float $createdAt;

    public ?float $startedAt;

    public ?float $completedAt;

    public function __construct(
        string $subject,
        string $description = '',
        ?string $activeForm = null,
        ?string $parentId = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(4));
        $this->subject = $subject;
        $this->description = $description;
        $this->status = TaskStatus::Pending;
        $this->activeForm = $activeForm;
        $this->parentId = $parentId;
        $this->blockedBy = [];
        $this->blocks = [];
        $this->metadata = [];
        $this->createdAt = microtime(true);
        $this->startedAt = null;
        $this->completedAt = null;
    }

    public function transitionTo(TaskStatus $status): void
    {
        if ($status === TaskStatus::InProgress && $this->startedAt === null) {
            $this->startedAt = microtime(true);
        }

        if ($status->isTerminal() && $this->completedAt === null) {
            $this->completedAt = microtime(true);
        }

        $this->status = $status;
    }

    public function elapsed(): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }

        $end = $this->completedAt ?? microtime(true);

        return round($end - $this->startedAt, 1);
    }

    public function toSummary(): string
    {
        $line = "{$this->status->icon()} {$this->subject}";
        $elapsed = $this->elapsed();

        if ($this->status === TaskStatus::InProgress) {
            $line .= $elapsed !== null ? " ({$elapsed}s)" : ' (running...)';
        } elseif ($this->status->isTerminal() && $elapsed !== null) {
            $line .= " ({$elapsed}s)";
        }

        return $line;
    }

    public function toDetail(): string
    {
        $lines = [];
        $lines[] = "ID: {$this->id}";
        $lines[] = "Subject: {$this->subject}";
        $lines[] = "Status: {$this->status->icon()} {$this->status->value}";

        if ($this->description !== '') {
            $lines[] = "Description: {$this->description}";
        }
        if ($this->activeForm !== null) {
            $lines[] = "Active form: {$this->activeForm}";
        }
        if ($this->parentId !== null) {
            $lines[] = "Parent: {$this->parentId}";
        }

        $elapsed = $this->elapsed();
        if ($elapsed !== null) {
            $lines[] = "Elapsed: {$elapsed}s";
        }

        if ($this->blockedBy !== []) {
            $lines[] = 'Blocked by: ' . implode(', ', $this->blockedBy);
        }
        if ($this->blocks !== []) {
            $lines[] = 'Blocks: ' . implode(', ', $this->blocks);
        }
        if ($this->metadata !== []) {
            $lines[] = 'Metadata: ' . json_encode($this->metadata, JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }
}
