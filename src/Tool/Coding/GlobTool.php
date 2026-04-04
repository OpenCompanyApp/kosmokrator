<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Finds files matching a glob pattern, with recursive (`**`) support.
 * Use to discover project structure, locate specific file types, or enumerate test files.
 * Automatically skips hidden directories, `vendor/`, and `node_modules/` during recursion.
 */
class GlobTool extends AbstractTool
{
    public function name(): string
    {
        return 'glob';
    }

    public function description(): string
    {
        return 'Find files matching a glob pattern. Returns matching file paths sorted by name.';
    }

    public function parameters(): array
    {
        return [
            'pattern' => ['type' => 'string', 'description' => 'Glob pattern to match (e.g., "**/*.php", "src/**/*.ts", "*.json")'],
            'path' => ['type' => 'string', 'description' => 'Directory to search in. Defaults to current working directory.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['pattern'];
    }

    /**
     * @param  array{pattern: string, path?: string}  $args  Glob pattern and optional base directory
     * @return ToolResult Sorted list of matching relative paths (capped at 200), or "no matches" message
     */
    protected function handle(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $basePath = rtrim($args['path'] ?? getcwd(), '/');

        if (! is_dir($basePath)) {
            return ToolResult::error("Directory not found: {$basePath}");
        }

        $fullPattern = $basePath.'/'.ltrim($pattern, '/');
        $files = $this->recursiveGlob($fullPattern);

        // Make paths relative to basePath
        $prefixLen = strlen($basePath) + 1;
        $relative = array_unique(array_map(fn (string $f) => substr($f, $prefixLen), $files));
        sort($relative);

        if (count($relative) > 200) {
            $relative = array_slice($relative, 0, 200);
            $relative[] = '... (truncated at 200 files)';
        }

        if (empty($relative)) {
            return ToolResult::success("No files matching '{$pattern}' in {$basePath}");
        }

        return ToolResult::success(implode("\n", $relative));
    }

    /**
     * @return string[]
     */
    private function recursiveGlob(string $pattern): array
    {
        // Handle ** (recursive directory matching)
        if (str_contains($pattern, '**')) {
            return $this->globStar($pattern);
        }

        return glob($pattern) ?: [];
    }

    /**
     * @return string[]
     */
    private function globStar(string $pattern): array
    {
        // Split on ** and handle recursion
        $parts = explode('**', $pattern, 2);
        $base = rtrim($parts[0], '/');
        $rest = ltrim($parts[1] ?? '', '/');

        if (! is_dir($base)) {
            return [];
        }

        $results = [];

        // Match in the base directory itself
        if ($rest !== '') {
            foreach (glob($base.'/'.$rest) ?: [] as $match) {
                $results[] = $match;
            }
        }

        // Recurse into subdirectories
        $dirs = glob($base.'/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        foreach ($dirs as $dir) {
            $basename = basename($dir);
            // Skip hidden directories and common excludes
            if ($basename[0] === '.' || $basename === 'vendor' || $basename === 'node_modules') {
                continue;
            }

            if ($rest !== '') {
                foreach (glob($dir.'/'.$rest) ?: [] as $match) {
                    $results[] = $match;
                }
            }
            // Continue recursing with **
            foreach ($this->globStar($dir.'/**'.($rest !== '' ? '/'.$rest : '')) as $match) {
                $results[] = $match;
            }
        }

        return array_filter($results, 'is_file');
    }
}
