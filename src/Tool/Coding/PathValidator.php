<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\Permission\PathResolver;

/**
 * Validates that file paths stay within the project root or allowed paths.
 *
 * Resolves paths to absolute form (handling non-existent files via parent directory)
 * and ensures the resolved path does not escape the project root or any allowed path prefix.
 */
final class PathValidator
{
    /**
     * Resolve a path to its absolute form and verify it stays within the project root or an allowed path.
     *
     * @param  string  $path  The file path to validate (relative or absolute)
     * @param  string  $projectRoot  The project root directory to contain the path
     * @param  string[]  $allowedPaths  Additional allowed path prefixes (pre-resolved to realpaths)
     * @param  bool  $allowSymlinkComponents  Whether existing symlink path segments may be followed
     * @return string The resolved absolute path
     *
     * @throws \RuntimeException if the path escapes the project root and all allowed paths
     */
    public static function resolveAndValidatePath(
        string $path,
        string $projectRoot,
        array $allowedPaths = [],
        bool $allowSymlinkComponents = true,
    ): string {
        // Resolve project root to its realpath (macOS /var → /private/var, etc.)
        $resolvedRoot = realpath($projectRoot) ?: $projectRoot;

        if (! $allowSymlinkComponents && PathResolver::containsSymlinkComponent($path, $resolvedRoot)) {
            throw new \RuntimeException("Path uses a symlink component and cannot be safely mutated: {$path}");
        }

        $resolved = PathResolver::resolve($path);

        if ($resolved === null) {
            $resolved = PathResolver::resolveViaExistingAncestor($path);
        }

        if ($resolved === null) {
            throw new \RuntimeException("Path escapes project root: {$path}");
        }

        if ($resolved === $resolvedRoot || str_starts_with($resolved, $resolvedRoot.'/')) {
            return $resolved;
        }

        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($resolved, $allowed.'/') || $resolved === $allowed) {
                return $resolved;
            }
        }

        throw new \RuntimeException("Path escapes project root: {$path}");
    }
}
