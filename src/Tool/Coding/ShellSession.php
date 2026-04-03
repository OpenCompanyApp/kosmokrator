<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;

/**
 * Stateful wrapper around an Amp Process that tracks output buffering, read offsets, and lifecycle.
 *
 * Each instance is managed by ShellSessionManager and addressed by its public $id.
 * Output is accumulated in a grow-only buffer with a read-offset cursor so consumers
 * can drain incrementally without losing data.
 */
final class ShellSession
{
    /** Accumulated stdout+stderr output. */
    private string $buffer = '';

    /** Byte offset into $buffer up to which has already been consumed by readUnread(). */
    private int $readOffset = 0;

    /** Process exit code, set once the process finishes. */
    private ?int $exitCode = null;

    /** Whether this session was explicitly killed (vs. natural exit or timeout). */
    private bool $killed = false;

    /** Revolt event-loop timer ID for the session timeout, or null if none is scheduled. */
    private ?string $timeoutTimerId = null;

    /** Timestamp of the last buffer write or read (used for idle cleanup). */
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

    /** Append raw output from stdout/stderr to the internal buffer. */
    public function appendOutput(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->buffer .= $chunk;
        $this->touch();
    }

    /** Append a system-generated informational line (e.g. "Exit code: 0"), ensuring it starts on its own line. */
    public function appendSystemLine(string $line): void
    {
        // Ensure a leading newline if the buffer doesn't already end with one
        $prefix = ($this->buffer !== '' && ! str_ends_with($this->buffer, "\n")) ? "\n" : '';
        $this->buffer .= $prefix.$line."\n";
        $this->touch();
    }

    /** Return all output appended since the last readUnread() call, advancing the cursor. */
    public function readUnread(): string
    {
        $chunk = substr($this->buffer, $this->readOffset);
        $this->readOffset = strlen($this->buffer);
        $this->touch();

        return $chunk;
    }

    /** Whether there is output that hasn't been consumed by readUnread() yet. */
    public function hasUnreadOutput(): bool
    {
        return $this->readOffset < strlen($this->buffer);
    }

    /** Record the exit code once the underlying process terminates. */
    public function markExited(int $exitCode): void
    {
        $this->exitCode = $exitCode;
        $this->touch();
    }

    /** Get the process exit code, or null if still running. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** Whether the process is still alive (no exit code and Amp reports running). */
    public function isRunning(): bool
    {
        return $this->exitCode === null && $this->process->isRunning();
    }

    /** Whether the session is fully consumed: exited with no unread output remaining. */
    public function isDrained(): bool
    {
        return $this->exitCode !== null && ! $this->hasUnreadOutput();
    }

    /** Update the last-activity timestamp to now. */
    public function touch(): void
    {
        $this->lastActiveAt = microtime(true);
    }

    /** Mark this session as having been explicitly killed. */
    public function markKilled(): void
    {
        $this->killed = true;
    }

    /** Whether this session was killed by the agent or a timeout (not a natural exit). */
    public function wasKilled(): bool
    {
        return $this->killed;
    }

    /** Store the Revolt timer ID used for the session timeout so it can be cancelled later. */
    public function setTimeoutTimerId(?string $timerId): void
    {
        $this->timeoutTimerId = $timerId;
    }

    /** Get the timeout timer ID, or null if not scheduled. */
    public function timeoutTimerId(): ?string
    {
        return $this->timeoutTimerId;
    }
}
