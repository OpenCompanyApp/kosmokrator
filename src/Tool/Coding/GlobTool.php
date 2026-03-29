<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;
use Symfony\Component\Finder\Finder;

class GlobTool implements ToolInterface
{
    public function name(): string { return 'glob'; }

    public function description(): string
    {
        return 'Find files matching a glob pattern. Returns matching file paths.';
    }

    public function parameters(): array
    {
        return [
            'pattern' => ['type' => 'string', 'description' => 'Glob pattern to match (e.g., "**/*.php", "src/**/*.ts")'],
            'path' => ['type' => 'string', 'description' => 'Directory to search in. Defaults to current working directory.'],
        ];
    }

    public function requiredParameters(): array { return ['pattern']; }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? getcwd();

        if (! is_dir($path)) {
            return ToolResult::error("Directory not found: {$path}");
        }

        $finder = new Finder();
        $finder->files()->in($path)->path($pattern)->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRelativePathname();
            if (count($files) >= 200) {
                break;
            }
        }

        if (empty($files)) {
            return ToolResult::success("No files matching '{$pattern}' in {$path}");
        }

        return ToolResult::success(implode("\n", $files));
    }
}
