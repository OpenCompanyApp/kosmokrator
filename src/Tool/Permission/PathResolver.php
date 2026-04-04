<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

/**
 * Resolves file paths to absolute form for permission checking.
 *
 * Handles files that don't exist yet by resolving the parent directory
 * and appending the basename. This is security-critical code used by
 * both PermissionEvaluator and GuardianEvaluator.
 */
final class PathResolver
{
    /**
     * Resolve a path to its absolute form, handling non-existent files.
     *
     * @return string|null Resolved absolute path, or null if unresolvable
     */
    public static function resolve(string $path): ?string
    {
        if ($path === '' || $path === '.') {
            return null;
        }

        $resolved = realpath($path);
        if ($resolved !== false) {
            return $resolved;
        }

        // File doesn't exist yet — resolve parent directory
        $parent = realpath(dirname($path));
        if ($parent === false) {
            // Unresolvable path — return null so callers treat it as untrusted.
            // BlockedPathCheck still checks the raw path against blocked patterns,
            // and GuardianEvaluator treats null as "not inside project" (deny).
            return null;
        }

        return $parent.'/'.basename($path);
    }
}
