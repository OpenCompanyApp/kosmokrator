<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class FileReadTool implements ToolInterface
{
    private const LARGE_FILE_THRESHOLD = 10 * 1024 * 1024;

    public function name(): string { return 'file_read'; }

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

    public function requiredParameters(): array { return ['path']; }

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

        $fileSize = filesize($path);
        if ($fileSize !== false && $fileSize > self::LARGE_FILE_THRESHOLD) {
            return $this->readLargeFile($path, $offset, $limit);
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

        return ToolResult::success(rtrim($output));
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
}
