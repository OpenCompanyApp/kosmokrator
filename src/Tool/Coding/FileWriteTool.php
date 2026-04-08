<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\IO\AtomicFileWriter;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Throwable;

/**
 * Writes entire file contents, creating the file and any missing parent directories.
 * Use for new files or complete overwrites; for targeted edits prefer FileEditTool or ApplyPatchTool.
 * Overwrites existing files without confirmation — the LLM should read first to avoid data loss.
 */
class FileWriteTool extends AbstractTool
{
    /**
     * @param  string|null  $projectRoot  Absolute path to project root for boundary enforcement
     * @param  string[]  $allowedPaths  Pre-resolved path prefixes allowed in addition to the project root
     */
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly array $allowedPaths = [],
    ) {}

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

    /**
     * @param  array{path: string, content: string}  $args  File path and full content to write
     * @return ToolResult Summary with line count, or error if write or directory creation failed
     */
    protected function handle(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $content = $args['content'] ?? '';

        // Validate path stays within project root
        if ($this->projectRoot !== null) {
            try {
                $path = PathValidator::resolveAndValidatePath($path, $this->projectRoot, $this->allowedPaths);
            } catch (Throwable $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        try {
            AtomicFileWriter::write($path, $content);
        } catch (\RuntimeException) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        $lines = substr_count($content, "\n") + 1;

        return ToolResult::success("Wrote {$lines} lines to {$path}");
    }
}
