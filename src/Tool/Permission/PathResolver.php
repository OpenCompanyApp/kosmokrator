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

    /**
     * Resolve a path intended for mutation.
     *
     * Mutating through symlink components is rejected because the link target
     * can be retargeted after approval but before the file operation runs.
     */
    public static function resolveForMutation(string $path): ?string
    {
        if (self::containsSymlinkComponent($path)) {
            return null;
        }

        return self::resolve($path);
    }

    /**
     * Resolve by walking up to the first existing ancestor, then reconstructing
     * the requested path below that ancestor. Useful for tools that can create
     * missing parent directories after boundary validation.
     */
    public static function resolveViaExistingAncestor(string $path): ?string
    {
        if ($path === '' || $path === '.') {
            return null;
        }

        $trail = [];
        $current = $path;

        while (true) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }

            $trail[] = basename($current);
            $resolved = realpath($parent);

            if ($resolved !== false) {
                return $resolved.'/'.implode('/', array_reverse($trail));
            }

            $current = $parent;
        }

        return null;
    }

    /**
     * Return true when any existing path segment is a symbolic link.
     */
    public static function containsSymlinkComponent(string $path, ?string $boundaryRoot = null): bool
    {
        if ($path === '' || $path === '.') {
            return false;
        }

        $absolute = self::absolutize($path);
        $resolvedBoundaryRoot = $boundaryRoot !== null
            ? rtrim((realpath($boundaryRoot) ?: $boundaryRoot), '/')
            : null;
        $parts = explode('/', trim($absolute, '/'));
        $current = '';

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                $current = dirname($current === '' ? '/' : $current);

                continue;
            }

            $current .= '/'.$part;

            if (is_link($current)) {
                $resolvedCurrent = realpath($current);
                if (
                    $resolvedBoundaryRoot !== null
                    && $resolvedCurrent !== false
                    && ($resolvedBoundaryRoot === $resolvedCurrent || str_starts_with($resolvedBoundaryRoot, $resolvedCurrent.'/'))
                ) {
                    continue;
                }

                return true;
            }

            if (! file_exists($current)) {
                return false;
            }
        }

        return false;
    }

    private static function absolutize(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return (getcwd() ?: '.').'/'.$path;
    }
}
