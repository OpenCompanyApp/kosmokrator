<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class FileWriteTool implements ToolInterface
{
    public function name(): string
    {
        return 'file_write';
    }

    public function description(): string
    {
        return 'Write a whole file. Use for new files or full overwrites. Use file_edit or apply_patch for targeted edits.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Path to the file to write'],
            'content' => ['type' => 'string', 'description' => 'The content to write to the file'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['path', 'content'];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $content = $args['content'] ?? '';

        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        if (file_put_contents($path, $content) === false) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        $lines = substr_count($content, "\n") + 1;

        return ToolResult::success("Wrote {$lines} lines to {$path}");
    }
}
