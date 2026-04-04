<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\ByteStream\buffer;

/**
 * Executes shell commands via `sh -c` and returns combined stdout/stderr with the exit code.
 * Use for running tests, git operations, package installs, and other CLI tasks.
 * Streams output in real time via an optional progress callback; enforces a configurable timeout.
 */
class BashTool extends AbstractTool
{
    /** @var (\Closure(string): void)|null */
    public ?\Closure $progressCallback = null;

    private int $timeout;

    private LoggerInterface $log;

    /**
     * @param  int  $timeout  Default per-command timeout in seconds
     * @param  LoggerInterface|null  $log  Optional PSR-3 logger
     */
    public function __construct(int $timeout = 120, ?LoggerInterface $log = null)
    {
        $this->timeout = $timeout;
        $this->log = $log ?? new NullLogger;
    }

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a shell command and return its output. Use for running tests, installing packages, git operations, etc.';
    }

    public function parameters(): array
    {
        return [
            'command' => ['type' => 'string', 'description' => 'The shell command to execute'],
            'timeout' => ['type' => 'integer', 'description' => 'Timeout in seconds for the command. Default 120. Use higher values for long-running commands (e.g. 3600 for test suites).'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['command'];
    }

    /**
     * @param  array{command: string, timeout?: int}  $args  Command and optional timeout override
     * @return ToolResult Combined stdout+stderr output with exit code, or error on timeout/failure
     */
    protected function handle(array $args): ToolResult
    {
        $command = trim($args['command'] ?? '');

        if ($command === '') {
            return ToolResult::error('No command provided');
        }

        $timeout = (int) ($args['timeout'] ?? $this->timeout);
        $timeout = max(1, min($timeout, 7200));

        if ($timeout !== $this->timeout) {
            $this->log->debug('Bash using custom timeout', ['timeout' => $timeout, 'default' => $this->timeout]);
        }

        $startTime = microtime(true);
        try {
            // Amp Process — fiber-aware, yields to event loop during execution
            $process = Process::start(['sh', '-c', $command]);

            // Timeout watchdog — kills the process if it exceeds the limit
            $timedOut = false;
            $timerId = EventLoop::delay($timeout, function () use ($process, &$timedOut): void {
                $timedOut = true;
                if ($process->isRunning()) {
                    $process->kill();
                }
            });

            // Read stdout/stderr concurrently, streaming chunks via progress callback
            $progressCb = $this->progressCallback;
            $stdoutFuture = \Amp\async(function () use ($process, $progressCb): string {
                $buf = '';
                $stream = $process->getStdout();
                while (($chunk = $stream->read()) !== null) {
                    $buf .= $chunk;
                    if ($progressCb !== null) {
                        $progressCb($buf);
                    }
                }

                return $buf;
            });
            $stderrFuture = \Amp\async(fn () => buffer($process->getStderr()));
            $exitCode = $process->join();
            $output = $stdoutFuture->await();
            $errorOutput = $stderrFuture->await();

            if ($timedOut) {
                EventLoop::cancel($timerId);

                return ToolResult::error("Process timed out after {$timeout}s");
            }
        } catch (\Throwable $e) {
            if (isset($timerId)) {
                EventLoop::cancel($timerId);
            }
            $this->log->warning('Bash process error', [
                'command' => mb_substr($command, 0, 100),
                'error' => $e->getMessage(),
                'elapsed' => round(microtime(true) - $startTime, 2),
                'timeout' => $timeout,
            ]);

            return ToolResult::error("Process error: {$e->getMessage()}");
        }

        EventLoop::cancel($timerId);

        $this->log->debug('Bash command complete', [
            'command' => mb_substr($command, 0, 100),
            'exit_code' => $exitCode,
            'elapsed' => round(microtime(true) - $startTime, 2),
            'output_length' => strlen($output) + strlen($errorOutput),
        ]);

        $result = '';
        if ($output !== '') {
            $result .= $output;
        }
        if ($errorOutput !== '') {
            $result .= ($result !== '' ? "\n" : '').$errorOutput;
        }
        if ($result === '') {
            $result = '(no output)';
        }

        $result .= "\nExit code: {$exitCode}";

        return new ToolResult($result, $exitCode === 0);
    }
}
