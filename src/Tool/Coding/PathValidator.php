<?php

declare(strict_types=1);

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
        // Resolve project root to its realpath (macOS /var → /private/var, etc.)
        $resolvedRoot = realpath($projectRoot) ?: $projectRoot;

        $resolved = realpath($path);

        if ($resolved === false) {
            // File doesn't exist yet — walk up the directory tree to find an existing ancestor
            $dir = dirname($path);
            $basename = basename($path);
            $parts = [];

            while ($dir !== '/' && $dir !== '.' && realpath($dir) === false) {
                $parts[] = basename($dir);
                $dir = dirname($dir);
            }

            $resolvedDir = realpath($dir);
            if ($resolvedDir !== false) {
                $resolved = $resolvedDir.'/'.implode('/', array_reverse($parts)).($parts !== [] ? '/' : '').$basename;
            }
        }

        if ($resolved === false || ! str_starts_with($resolved, $resolvedRoot)) {
            throw new \RuntimeException("Path escapes project root: {$path}");
        }

        return $resolved;
    }
}
