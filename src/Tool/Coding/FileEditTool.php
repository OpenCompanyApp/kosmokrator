<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Performs a single exact find-and-replace in an existing file.
 * Use when only one targeted replacement is needed; for multi-hunk or multi-file changes use ApplyPatchTool.
 * Streams the file to avoid loading it entirely into memory (constant memory regardless of file size).
 */
class FileEditTool extends AbstractTool
{
    private const CHUNK_SIZE = 65536;

    public function name(): string
    {
        return 'file_edit';
    }

    public function description(): string
    {
        return 'One exact replacement in one existing file. Requires old_string to match exactly once. Use apply_patch for multi-hunk or multi-file edits.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Path to the file to edit'],
            'old_string' => ['type' => 'string', 'description' => 'The exact string to find and replace'],
            'new_string' => ['type' => 'string', 'description' => 'The string to replace it with'],
        ];
    }

    /**
     * @param  array{path: string, old_string: string, new_string: string}  $args
     * @return ToolResult Edit summary with line delta, or error if not found / ambiguous / write failed
     */
    protected function handle(array $args): ToolResult
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

        // Phase 1: Streaming uniqueness check — find byte offset of the single match
        $match = $this->findUniqueMatch($path, $oldString);

        if ($match === -1) {
            return ToolResult::error("old_string not found in {$path}. Make sure it matches exactly including whitespace.");
        }

        if ($match === -2) {
            return ToolResult::error("old_string found multiple times in {$path}. It must be unique. Provide more context.");
        }

        // Phase 2: Streaming targeted write via temp file + atomic rename
        if (! $this->patchFile($path, $match, strlen($oldString), $newString)) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        $removedLines = substr_count($oldString, "\n");
        $addedLines = substr_count($newString, "\n");

        return ToolResult::success("Edited {$path} (-{$removedLines}, +{$addedLines} lines)");
    }

    /**
     * Stream-read a file to find exactly one occurrence of $needle.
     * Returns the byte offset of the match, -1 if not found, -2 if multiple.
     * Uses O(chunkSize + strlen(needle)) memory regardless of file size.
     */
    private function findUniqueMatch(string $path, string $needle): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return -1;
        }

        try {
            $needleLen = strlen($needle);
            $overlap = '';
            $count = 0;
            $matchOffset = -1;
            $bytesRead = 0;

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $buffer = $overlap.$chunk;
                $searchOffset = 0;

                while (($pos = strpos($buffer, $needle, $searchOffset)) !== false) {
                    $count++;
                    if ($count === 1) {
                        $matchOffset = $bytesRead + $pos - strlen($overlap);
                    }
                    if ($count >= 2) {
                        return -2; // Bail early
                    }
                    $searchOffset = $pos + 1;
                }

                // Maintain overlap of needleLen bytes from buffer end for boundary matching
                if (! feof($handle) && $needleLen > 0) {
                    $overlap = substr($buffer, -$needleLen);
                } else {
                    $overlap = '';
                }

                $bytesRead += strlen($chunk);
            }

            return $count === 1 ? $matchOffset : -1;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Patch a file by replacing $oldLen bytes at $offset with $newString.
     * Uses streaming copy to avoid loading the full file into memory.
     * Writes to a temp file then atomically renames over the original.
     */
    private function patchFile(string $path, int $offset, int $oldLen, string $newString): bool
    {
        $fileSize = filesize($path);
        $tmpPath = $path.'.tmp.'.getmypid();

        $src = fopen($path, 'r');
        if ($src === false) {
            return false;
        }

        $dst = fopen($tmpPath, 'w');
        if ($dst === false) {
            fclose($src);

            return false;
        }

        try {
            // Copy bytes before the match
            if ($offset > 0 && stream_copy_to_stream($src, $dst, $offset) !== $offset) {
                return false;
            }

            // Skip the old string
            fseek($src, $offset + $oldLen);

            // Write the new string
            if ($newString !== '') {
                fwrite($dst, $newString);
            }

            // Copy everything after the match
            $remaining = $fileSize - ($offset + $oldLen);
            if ($remaining > 0) {
                stream_copy_to_stream($src, $dst, $remaining);
            }
        } finally {
            fclose($src);
            fclose($dst);
        }

        $renamed = rename($tmpPath, $path);
        if (! $renamed) {
            @unlink($tmpPath);
        }

        return $renamed;
    }
}
