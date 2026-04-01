<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;

final class ShellSession
{
    private string $buffer = '';

    private int $readOffset = 0;

    private ?int $exitCode = null;

    private bool $killed = false;

    private ?string $timeoutTimerId = null;

    public float $lastActiveAt;

    public function __construct(
        public readonly string $id,
        public readonly Process $process,
        public readonly string $command,
        public readonly string $cwd,
        public readonly bool $readOnly,
        public readonly float $startedAt,
        public readonly int $timeoutSeconds,
    ) {
        $this->lastActiveAt = $startedAt;
    }

    public function appendOutput(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->buffer .= $chunk;
        $this->touch();
    }

    public function appendSystemLine(string $line): void
    {
        $prefix = ($this->buffer !== '' && ! str_ends_with($this->buffer, "\n")) ? "\n" : '';
        $this->buffer .= $prefix.$line."\n";
        $this->touch();
    }

    public function readUnread(): string
    {
        $chunk = substr($this->buffer, $this->readOffset);
        $this->readOffset = strlen($this->buffer);
        $this->touch();

        return $chunk;
    }

    public function hasUnreadOutput(): bool
    {
        return $this->readOffset < strlen($this->buffer);
    }

    public function markExited(int $exitCode): void
    {
        $this->exitCode = $exitCode;
        $this->touch();
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function isRunning(): bool
    {
        return $this->exitCode === null && $this->process->isRunning();
    }

    public function isDrained(): bool
    {
        return $this->exitCode !== null && ! $this->hasUnreadOutput();
    }

    public function touch(): void
    {
        $this->lastActiveAt = microtime(true);
    }

    public function markKilled(): void
    {
        $this->killed = true;
    }

    public function wasKilled(): bool
    {
        return $this->killed;
    }

    public function setTimeoutTimerId(?string $timerId): void
    {
        $this->timeoutTimerId = $timerId;
    }

    public function timeoutTimerId(): ?string
    {
        return $this->timeoutTimerId;
    }
}
