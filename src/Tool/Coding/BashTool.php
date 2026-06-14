<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

/**
 * Executes shell commands via `sh -c` and returns combined stdout/stderr with the exit code.
 * Use for running tests, git operations, package installs, and other CLI tasks.
 * Streams output in real time via an optional progress callback; enforces a configurable timeout.
 */
class BashTool extends AbstractTool
{
    private const MAX_CAPTURE_BYTES = 50_000;

    /** @var (\Closure(string): void)|null */
    public ?\Closure $progressCallback = null;

    private int $timeout;

    private LoggerInterface $log;

    /**
     * @param  int  $timeout  Default per-command timeout in seconds
     * @param  LoggerInterface|null  $log  Optional PSR-3 logger
     */
    public function __construct(
        int $timeout = 120,
        ?LoggerInterface $log = null,
        private ?string $storagePath = null,
        private readonly ?ShellSessionManager $sessions = null,
        private readonly int $backgroundWaitMs = 250,
    ) {
        $this->timeout = $timeout;
        $this->log = $log ?? new NullLogger;
        if ($this->storagePath === null) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
            $this->storagePath = $home.'/.kosmo/data/truncations';
        }
    }

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a shell command and return its output. Use for short terminal operations. For long tests, builds, installs, dev servers, watchers, or commands where the result is not needed immediately, set background=true and wait for the completion notification instead of polling.';
    }

    public function parameters(): array
    {
        return [
            'command' => ['type' => 'string', 'description' => 'The shell command to execute'],
            'timeout' => ['type' => 'integer', 'description' => 'Timeout in seconds for the command. Default 120. Use higher values for long-running commands (e.g. 3600 for test suites).'],
            'background' => ['type' => 'boolean', 'description' => 'Run the command in the background and return a shell session id immediately. Use for long tests, builds, installs, dev servers, and commands whose output can arrive later.'],
            'wait_ms' => ['type' => 'integer', 'description' => 'For background commands, how long to wait for initial output before returning. Default 250ms, max 5000ms.'],
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
        $background = (bool) ($args['background'] ?? false);

        if ($timeout !== $this->timeout) {
            $this->log->debug('Bash using custom timeout', ['timeout' => $timeout, 'default' => $this->timeout]);
        }

        if ($background) {
            return $this->startBackground($command, $timeout, $args);
        }

        $startTime = microtime(true);
        try {
            // Amp Process — fiber-aware, yields to event loop during execution
            $process = Process::start(['sh', '-c', $command]);
            $process->getStdin()->close();

            // Timeout watchdog — kills the process if it exceeds the limit
            $timedOut = false;
            $timerId = EventLoop::delay($timeout, function () use ($process, &$timedOut): void {
                $timedOut = true;
                if ($process->isRunning()) {
                    $process->kill();
                }
            });

            // Read stdout/stderr concurrently, keeping only a bounded preview in memory.
            $progressCb = $this->progressCallback;
            $stdoutFuture = \Amp\async(fn (): array => $this->readBoundedStream(
                $process->getStdout(),
                'stdout',
                $progressCb,
            ));
            $stderrFuture = \Amp\async(fn (): array => $this->readBoundedStream(
                $process->getStderr(),
                'stderr',
                $progressCb,
            ));
            $exitCode = $process->join();
            $stdout = $stdoutFuture->await();
            $stderr = $stderrFuture->await();
            $output = $stdout['preview'];
            $errorOutput = $stderr['preview'];

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
            'output_length' => $stdout['bytes'] + $stderr['bytes'],
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

        $truncationNotes = [];
        if ($stdout['truncated']) {
            $truncationNotes[] = 'stdout saved to '.$stdout['path'];
        }
        if ($stderr['truncated']) {
            $truncationNotes[] = 'stderr saved to '.$stderr['path'];
        }
        if ($truncationNotes !== []) {
            $result .= "\n\n[truncated - full ".implode('; ', $truncationNotes).'; inspect with targeted grep/file_read rather than pasting it back into context]';
        }

        $result .= "\nExit code: {$exitCode}";

        return new ToolResult($result, $exitCode === 0, [
            'stdout' => $output,
            'stderr' => $errorOutput,
            'exit_code' => $exitCode,
            'stdout_bytes' => $stdout['bytes'],
            'stderr_bytes' => $stderr['bytes'],
            'stdout_path' => $stdout['path'],
            'stderr_path' => $stderr['path'],
        ]);
    }

    /**
     * @param  array{wait_ms?: int}  $args
     */
    private function startBackground(string $command, int $timeout, array $args): ToolResult
    {
        if ($this->sessions === null) {
            return ToolResult::error('Background bash execution is unavailable: shell session manager is not configured.');
        }

        $waitMs = isset($args['wait_ms'])
            ? max(0, min((int) $args['wait_ms'], 5000))
            : max(0, min($this->backgroundWaitMs, 5000));

        $result = $this->sessions->start(
            command: $command,
            timeoutSeconds: $timeout,
            waitMs: $waitMs,
            background: true,
            closeStdin: true,
        );

        $lines = [
            "Background command started as session {$result['id']}.",
            'Results will be injected when the command finishes. Use shell_read to inspect progress or shell_kill to stop it.',
        ];

        $output = trim($result['output']);
        if ($output !== '' && ! str_contains($output, '(no new output yet)')) {
            $lines[] = '';
            $lines[] = $output;
        }

        return new ToolResult(implode("\n", $lines), true, [
            'session_id' => $result['id'],
            'background' => true,
        ]);
    }

    /**
     * @return array{preview: string, bytes: int, truncated: bool, path: ?string}
     */
    private function readBoundedStream(object $stream, string $name, ?\Closure $progressCb = null): array
    {
        $preview = '';
        $bytes = 0;
        $path = null;
        $handle = null;

        try {
            while (($chunk = $stream->read()) !== null) {
                $bytes += strlen($chunk);

                if ($handle !== null) {
                    fwrite($handle, $chunk);
                }

                $remaining = self::MAX_CAPTURE_BYTES - strlen($preview);
                if ($remaining > 0) {
                    $preview .= strlen($chunk) <= $remaining
                        ? $chunk
                        : substr($chunk, 0, $remaining);
                }

                if ($bytes > self::MAX_CAPTURE_BYTES && $handle === null) {
                    [$path, $handle] = $this->openSpoolFile($name);
                    fwrite($handle, $preview);
                    if (strlen($chunk) > $remaining) {
                        fwrite($handle, substr($chunk, $remaining));
                    }
                }

                if ($progressCb !== null) {
                    $progressCb($preview);
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return [
            'preview' => $this->sanitizePreview($preview),
            'bytes' => $bytes,
            'truncated' => $path !== null,
            'path' => $path,
        ];
    }

    /**
     * @return array{string, resource}
     */
    private function openSpoolFile(string $name): array
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $path = $this->storagePath.'/bash_'.$name.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.txt';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open output spool file: {$path}");
        }

        return [$path, $handle];
    }

    private function sanitizePreview(string $preview): string
    {
        if ($preview === '' || mb_check_encoding($preview, 'UTF-8')) {
            return $preview;
        }

        return mb_convert_encoding($preview, 'UTF-8', 'UTF-8');
    }
}
