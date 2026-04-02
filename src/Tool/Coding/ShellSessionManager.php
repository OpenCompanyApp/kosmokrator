<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class ShellSessionManager
{
    /** @var array<string, ShellSession> */
    private array $sessions = [];

    private int $nextId = 1;

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly int $defaultWaitMs = 100,
        private readonly int $defaultTimeoutSeconds = 120,
        private readonly int $idleTtlSeconds = 300,
    ) {}

    /**
     * @return array{id:string, output:string}
     */
    public function start(
        string $command,
        ?string $cwd = null,
        bool $readOnly = false,
        ?int $timeoutSeconds = null,
        ?int $waitMs = null,
    ): array {
        $this->cleanupIdleSessions();

        $cwd = $cwd !== null && trim($cwd) !== '' ? $cwd : (getcwd() ?: '.');
        $timeoutSeconds ??= $this->defaultTimeoutSeconds;
        $waitMs ??= $this->defaultWaitMs;

        $process = Process::start(['sh', '-c', $command], $cwd);
        $id = 'sh_'.$this->nextId++;
        $session = new ShellSession(
            id: $id,
            process: $process,
            command: $command,
            cwd: $cwd,
            readOnly: $readOnly,
            startedAt: microtime(true),
            timeoutSeconds: $timeoutSeconds,
        );

        $this->sessions[$id] = $session;
        $this->startBackgroundReaders($session);
        $this->registerTimeout($session);

        $initial = $this->waitForActivity($session, $waitMs);
        $header = "Session {$id} started in {$cwd}";

        return [
            'id' => $id,
            'output' => $initial !== '' ? "{$header}\n\n{$initial}" : "{$header}\n\n(no new output yet)",
        ];
    }

    public function write(string $id, string $input, bool $submit = true, ?int $waitMs = null): string
    {
        $this->cleanupIdleSessions();
        $session = $this->requireSession($id);

        if (! $session->isRunning()) {
            $output = $this->waitForActivity($session, 10);
            $this->forgetIfDrained($session);

            return $output !== '' ? $output : "Session {$id} has already exited.";
        }

        $payload = $submit ? $input."\n" : $input;
        $session->process->getStdin()->write($payload);
        $session->touch();

        $output = $this->waitForActivity($session, $waitMs ?? $this->defaultWaitMs);

        return $output !== '' ? $output : "Session {$id} accepted input. No new output yet.";
    }

    public function read(string $id, ?int $waitMs = null): string
    {
        $this->cleanupIdleSessions();
        $session = $this->requireSession($id);
        $output = $this->waitForActivity($session, $waitMs ?? $this->defaultWaitMs);

        return $output !== '' ? $output : ($session->isRunning()
            ? "Session {$id} is still running. No new output yet."
            : "Session {$id} has exited.");
    }

    public function kill(string $id): string
    {
        $this->cleanupIdleSessions();
        $session = $this->requireSession($id);

        if ($session->isRunning()) {
            $session->markKilled();
            $session->appendSystemLine("Session {$id} killed.");
            $session->process->kill();
        }

        $output = $this->waitForActivity($session, 50);

        return $output !== '' ? $output : "Session {$id} terminated.";
    }

    public function isReadOnly(string $id): bool
    {
        return $this->requireSession($id)->readOnly;
    }

    /**
     * Kill all running shell sessions. Called during teardown to prevent
     * background reader fibers from racing with PHP's shutdown sequence.
     */
    public function killAll(): void
    {
        foreach ($this->sessions as $session) {
            if ($session->isRunning()) {
                $session->markKilled();
                $session->process->kill();
            }

            if ($session->timeoutTimerId() !== null) {
                EventLoop::cancel($session->timeoutTimerId());
                $session->setTimeoutTimerId(null);
            }
        }

        $this->sessions = [];
    }

    private function requireSession(string $id): ShellSession
    {
        if (! isset($this->sessions[$id])) {
            throw new \RuntimeException("Unknown shell session '{$id}'.");
        }

        return $this->sessions[$id];
    }

    private function startBackgroundReaders(ShellSession $session): void
    {
        \Amp\async(function () use ($session): void {
            $stream = $session->process->getStdout();
            while (($chunk = $stream->read()) !== null) {
                $session->appendOutput($chunk);
            }
        });

        \Amp\async(function () use ($session): void {
            $stream = $session->process->getStderr();
            while (($chunk = $stream->read()) !== null) {
                $session->appendOutput($chunk);
            }
        });

        \Amp\async(function () use ($session): void {
            $exitCode = $session->process->join();
            if ($session->timeoutTimerId() !== null) {
                EventLoop::cancel($session->timeoutTimerId());
                $session->setTimeoutTimerId(null);
            }

            $session->markExited($exitCode);
            $session->appendSystemLine("Exit code: {$exitCode}");
        });
    }

    private function registerTimeout(ShellSession $session): void
    {
        $timerId = EventLoop::delay($session->timeoutSeconds, function () use ($session): void {
            if (! $session->isRunning()) {
                return;
            }

            $session->markKilled();
            $session->appendSystemLine("Session {$session->id} timed out after {$session->timeoutSeconds}s.");
            $session->process->kill();
        });

        $session->setTimeoutTimerId($timerId);
    }

    private function cleanupIdleSessions(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $id => $session) {
            if ($session->isRunning() && ($now - $session->lastActiveAt) > $this->idleTtlSeconds) {
                $this->log->info('Cleaning up idle shell session', ['session' => $id]);
                $session->markKilled();
                $session->appendSystemLine("Session {$id} expired after {$this->idleTtlSeconds}s idle.");
                $session->process->kill();
            }

            $this->forgetIfDrained($session);
        }
    }

    private function forgetIfDrained(ShellSession $session): void
    {
        if ($session->isDrained()) {
            unset($this->sessions[$session->id]);
        }
    }

    private function waitForActivity(ShellSession $session, int $waitMs): string
    {
        $waitMs = max(0, $waitMs);
        $deadline = microtime(true) + ($waitMs / 1000);

        do {
            $chunk = $session->readUnread();
            if ($chunk !== '') {
                $this->forgetIfDrained($session);

                return rtrim($chunk, "\n");
            }

            if (! $session->isRunning()) {
                if (! $session->process->isRunning() && $session->exitCode() === null && microtime(true) < $deadline) {
                    \Amp\delay(0.01);

                    continue;
                }

                $this->forgetIfDrained($session);

                return '';
            }

            if ($waitMs === 0) {
                break;
            }

            \Amp\delay(0.01);
        } while (microtime(true) < $deadline);

        $chunk = $session->readUnread();
        $this->forgetIfDrained($session);

        return rtrim($chunk, "\n");
    }
}
