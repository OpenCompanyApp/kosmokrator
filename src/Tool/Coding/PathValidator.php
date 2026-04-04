<?php

namespace Kosmokrator\Tool\Coding;

/**
 * Validates that file paths stay within the project root.
 *
 * Resolves paths to absolute form (handling non-existent files via parent directory)
 * and ensures the resolved path does not escape the project root.
 */
final class PathValidator
{
    /**
     * Resolve a path to its absolute form and verify it stays within the project root.
     *
     * @param  string  $path  The file path to validate (relative or absolute)
     * @param  string  $projectRoot  The project root directory to contain the path
     * @return string The resolved absolute path
     *
     * @throws \RuntimeException if the path escapes the project root
     */
    public static function resolveAndValidatePath(string $path, string $projectRoot): string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            // File doesn't exist yet — resolve parent directory + basename
            $parent = realpath(dirname($path));

            if ($parent !== false) {
                $resolved = $parent.'/'.basename($path);
            }
        }

        if ($resolved === false || ! str_starts_with($resolved, $projectRoot)) {
            throw new \RuntimeException("Path escapes project root: {$path}");
        }

        return $resolved;
    }
}
