<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class FileEditTool implements ToolInterface
{
    public function name(): string
    {
        return 'file_edit';
    }

    public function description(): string
    {
        return 'Perform a search-and-replace edit on a file. The old_string must match exactly (including whitespace and indentation). The old_string must be unique in the file.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Path to the file to edit'],
            'old_string' => ['type' => 'string', 'description' => 'The exact string to find and replace'],
            'new_string' => ['type' => 'string', 'description' => 'The string to replace it with'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['path', 'old_string', 'new_string'];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $oldString = $args['old_string'] ?? '';
        $newString = $args['new_string'] ?? '';

        if ($oldString === '') {
            return ToolResult::error('old_string cannot be empty.');
        }

        if (! file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $count = substr_count($content, $oldString);

        if ($count === 0) {
            return ToolResult::error("old_string not found in {$path}. Make sure it matches exactly including whitespace.");
        }

        if ($count > 1) {
            return ToolResult::error("old_string found {$count} times in {$path}. It must be unique. Provide more context.");
        }

        $newContent = str_replace($oldString, $newString, $content);
        if (file_put_contents($path, $newContent) === false) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        $removedLines = substr_count($oldString, "\n");
        $addedLines = substr_count($newString, "\n");

        return ToolResult::success("Edited {$path} (-{$removedLines}, +{$addedLines} lines)");
    }
}
