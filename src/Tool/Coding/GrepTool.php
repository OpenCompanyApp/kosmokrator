<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class GrepTool implements ToolInterface
{
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

        $output = [];
        $returnCode = 0;
        exec($fullCmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0 && empty($output)) {
            return ToolResult::success("No matches found for '{$pattern}'");
        }

        $result = implode("\n", array_slice($output, 0, 100));

        return ToolResult::success($result ?: "No matches found for '{$pattern}'");
    }

    private function hasRipgrep(): bool
    {
        exec('which rg 2>/dev/null', $output, $code);

        return $code === 0;
    }
}
