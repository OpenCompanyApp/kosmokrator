<?php

declare(strict_types=1);

namespace Kosmokrator\IO;

/**
 * Atomic file write utility using temp-file + rename.
 *
 * Prevents partial/corrupted files if the process crashes mid-write.
 * On POSIX systems, `rename()` is atomic, so the target file either
 * has the old content or the new content — never a partial write.
 */
final class AtomicFileWriter
{
    /**
     * Write content to a file atomically.
     *
     * Writes to a temporary file in the same directory, then renames it
     * to the target path. If writing fails, the temp file is cleaned up.
     *
     * @param  string  $path  Target file path
     * @param  string  $content  Content to write
     * @param  int  $permissions  Octal permissions for created directories (default 0755)
     *
     * @throws \RuntimeException If the write or rename fails
     */
    public static function write(string $path, string $content, int $permissions = 0755): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, $permissions, true);
        }

        $tmpPath = $dir.'/.kosmokrator_tmp_'.getmypid().'_'.mt_rand();

        if (file_put_contents($tmpPath, $content) === false) {
            @unlink($tmpPath);

            throw new \RuntimeException("Failed to write temporary file for: {$path}");
        }

        if (! @rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new \RuntimeException("Failed to rename temporary file to: {$path}");
        }
    }
}
