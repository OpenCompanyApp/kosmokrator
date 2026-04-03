<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Reads file contents with line numbers, supporting offset/limit for partial reads.
 * Caches previous reads to avoid re-sending identical content (saves tokens on repeat reads of unchanged files).
 * Large files (>10 MB) are streamed line-by-line to keep memory usage low.
 * Prefer this over shell commands (`cat`, `head`) for inspecting files.
 */
class FileReadTool implements ToolInterface
{
    private const LARGE_FILE_THRESHOLD = 10 * 1024 * 1024;

    private const UNCHANGED_RESULT_TEMPLATE = '[Unchanged since last file_read of %s (lines %d-%d); content omitted to save tokens]';

    /** @var array<string, true> */
    private array $readCache = [];

    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read the contents of a file. Returns the file contents with line numbers.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Absolute or relative path to the file to read'],
            'offset' => ['type' => 'integer', 'description' => 'Line number to start reading from (1-based). Optional.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum number of lines to read. Optional, defaults to 2000.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['path'];
    }

    /**
     * @param  array{path: string, offset?: int, limit?: int}  $args  File path and optional line range
     * @return ToolResult File contents with line numbers, or cached "unchanged" notice
     */
    public function execute(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $offset = max(1, (int) ($args['offset'] ?? 1));
        $limit = min(5000, max(1, (int) ($args['limit'] ?? 2000)));

        if (! file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if (! is_readable($path)) {
            return ToolResult::error("File not readable: {$path}");
        }

        if (is_dir($path)) {
            return ToolResult::error("Path is a directory, not a file: {$path}");
        }

        $cacheKey = $this->buildCacheKey($path, $offset, $limit);
        if (isset($this->readCache[$cacheKey])) {
            return ToolResult::success($this->formatUnchangedResult($path, $offset, $limit));
        }

        $fileSize = filesize($path);
        if ($fileSize !== false && $fileSize > self::LARGE_FILE_THRESHOLD) {
            $result = $this->readLargeFile($path, $offset, $limit);
            if ($result->success) {
                $this->readCache[$cacheKey] = true;
            }

            return $result;
        }

        $lines = file($path);
        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $totalLines = count($lines);
        $slice = array_slice($lines, $offset - 1, $limit, true);

        $output = '';
        foreach ($slice as $lineNum => $line) {
            $num = str_pad((string) ($lineNum + 1), strlen((string) $totalLines), ' ', STR_PAD_LEFT);
            $output .= "{$num}\t{$line}";
        }

        if ($offset + $limit - 1 < $totalLines) {
            $remaining = $totalLines - ($offset + $limit - 1);
            $output .= "\n... {$remaining} more lines";
        }

        $this->readCache[$cacheKey] = true;

        return ToolResult::success(rtrim($output));
    }

    /** Clears the read cache so subsequent reads will return full content again. */
    public function resetCache(): void
    {
        $this->readCache = [];
    }

    /**
     * Stream-read a large file line by line to avoid loading it entirely into memory.
     */
    private function readLargeFile(string $path, int $offset, int $limit): ToolResult
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ToolResult::error("Failed to open file: {$path}");
        }

        $lineNum = 0;
        $collected = [];

        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            if ($lineNum >= $offset && count($collected) < $limit) {
                $collected[$lineNum] = $line;
            }
        }
        $totalLines = $lineNum;
        fclose($handle);

        $output = '';
        $padWidth = strlen((string) $totalLines);
        foreach ($collected as $num => $line) {
            $numStr = str_pad((string) $num, $padWidth, ' ', STR_PAD_LEFT);
            $output .= "{$numStr}\t{$line}";
        }

        if ($offset + $limit - 1 < $totalLines) {
            $remaining = $totalLines - ($offset + $limit - 1);
            $output .= "\n... {$remaining} more lines";
        }

        return ToolResult::success(rtrim($output));
    }

    private function buildCacheKey(string $path, int $offset, int $limit): string
    {
        $resolvedPath = realpath($path) ?: $path;
        $mtime = filemtime($path);

        return implode(':', [
            $resolvedPath,
            $mtime === false ? '0' : (string) $mtime,
            (string) $offset,
            (string) $limit,
        ]);
    }

    private function formatUnchangedResult(string $path, int $offset, int $limit): string
    {
        $resolvedPath = realpath($path) ?: $path;
        $endLine = $offset + $limit - 1;

        return sprintf(self::UNCHANGED_RESULT_TEMPLATE, $resolvedPath, $offset, $endLine);
    }
}
