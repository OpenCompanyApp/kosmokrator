<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;
use Symfony\Component\Process\Process;

class GrepTool implements ToolInterface
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {}

    public function name(): string { return 'grep'; }

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

    public function requiredParameters(): array { return ['pattern']; }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '.';
        $glob = $args['glob'] ?? '';

        $cmd = $this->hasRipgrep() ? 'rg' : 'grep -rn';

        $escaped = escapeshellarg($pattern);
        $escapedPath = escapeshellarg($path);

        if ($this->hasRipgrep()) {
            $fullCmd = "rg -n --max-count=50 {$escaped} {$escapedPath}";
            if ($glob !== '') {
                $fullCmd .= ' --glob ' . escapeshellarg($glob);
            }
        } else {
            $fullCmd = "grep -rn --max-count=50 {$escaped} {$escapedPath}";
            if ($glob !== '') {
                $fullCmd .= ' --include=' . escapeshellarg($glob);
            }
        }

        $process = Process::fromShellCommandline($fullCmd);
        $process->setTimeout($this->timeout);
        $process->run();

        $output = trim($process->getOutput() . $process->getErrorOutput());

        if ($process->getExitCode() !== 0 && $output === '') {
            return ToolResult::success("No matches found for '{$pattern}'");
        }

        $lines = explode("\n", $output);
        $result = implode("\n", array_slice($lines, 0, 100));

        return ToolResult::success($result ?: "No matches found for '{$pattern}'");
    }

    private function hasRipgrep(): bool
    {
        $process = Process::fromShellCommandline('which rg');
        $process->run();

        return $process->isSuccessful();
    }
}
