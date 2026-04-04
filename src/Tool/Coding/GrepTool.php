<?php

namespace Kosmokrator\Tool\Coding;

use Amp\Process\Process;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Revolt\EventLoop;

use function Amp\ByteStream\buffer;

/**
 * Searches file contents for a regex pattern, returning matching lines with file paths and line numbers.
 * Automatically uses ripgrep (`rg`) when available for faster searches, falling back to GNU `grep`.
 * Use to find usages, trace code paths, or locate patterns across the codebase.
 */
class GrepTool extends AbstractTool
{
    private ?bool $hasRg = null;

    public function __construct(
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return 'Search file contents for a pattern using ripgrep (rg) or grep. Returns matching lines with file paths and line numbers.';
    }

    public function parameters(): array
    {
        return [
            'pattern' => ['type' => 'string', 'description' => 'Regex pattern to search for'],
            'path' => ['type' => 'string', 'description' => 'File or directory to search in. Defaults to current directory.'],
            'glob' => ['type' => 'string', 'description' => 'File glob filter (e.g., "*.php"). Optional.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['pattern'];
    }

    /**
     * @param  array{pattern: string, path?: string, glob?: string}  $args  Regex pattern, search path, and optional file filter
     * @return ToolResult Matching lines (up to 100), or "no matches" / error message
     */
    protected function handle(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '.';
        $glob = $args['glob'] ?? '';

        $useRg = $this->hasRipgrep();
        $escaped = escapeshellarg($pattern);
        $escapedPath = escapeshellarg($path);

        if ($useRg) {
            $fullCmd = "rg -n --max-count=50 {$escaped} {$escapedPath}";
            if ($glob !== '') {
                $fullCmd .= ' --glob '.escapeshellarg($glob);
            }
        } else {
            $fullCmd = "grep -rnE --max-count=50 {$escaped} {$escapedPath}";
            if ($glob !== '') {
                $fullCmd .= ' --include='.escapeshellarg($glob);
            }
        }

        $process = Process::start(['sh', '-c', $fullCmd]);

        // Timeout watchdog — kills the process if it exceeds the limit
        $timedOut = false;
        $timerId = EventLoop::delay($this->timeout, function () use ($process, &$timedOut): void {
            $timedOut = true;
            if ($process->isRunning()) {
                $process->kill();
            }
        });

        try {
            $stdoutFuture = \Amp\async(fn () => buffer($process->getStdout()));
            $stderrFuture = \Amp\async(fn () => buffer($process->getStderr()));
            $exitCode = $process->join();
            $stdout = trim($stdoutFuture->await());
            $stderr = trim($stderrFuture->await());
        } catch (\Throwable $e) {
            return ToolResult::error("Process error: {$e->getMessage()}");
        } finally {
            EventLoop::cancel($timerId);
        }

        if ($timedOut) {
            return ToolResult::error("Search timed out after {$this->timeout}s");
        }

        // Exit code 1 = no matches (normal), 2+ = error
        if ($exitCode === 1 || ($exitCode === 0 && $stdout === '')) {
            return ToolResult::success("No matches found for '{$pattern}'");
        }

        if ($exitCode >= 2) {
            $error = $stderr !== '' ? $stderr : 'Search failed';

            return ToolResult::error("grep error: {$error}");
        }

        $lines = explode("\n", $stdout);
        $result = implode("\n", array_slice($lines, 0, 100));

        return ToolResult::success($result ?: "No matches found for '{$pattern}'");
    }

    /** Checks whether ripgrep is available on the system PATH. */
    private function hasRipgrep(): bool
    {
        return $this->hasRg ??= (function (): bool {
            $process = Process::start(['which', 'rg']);

            return $process->join() === 0;
        })();
    }
}
