<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\PathResolver;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\PermissionRule;

/**
 * Denies access when a tool call's path argument matches a blocked path glob.
 *
 * Checked first in the chain — blocked paths are absolute denials that override
 * session grants, mode overrides, and every other check.
 */
class BlockedPathCheck implements PermissionCheck
{
    /** @param string[] $blockedPaths Glob patterns for paths that should be denied */
    public function __construct(
        private readonly array $blockedPaths,
    ) {}

    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        if ($this->blockedPaths === [] || ! isset($args['path'])) {
            return null;
        }

        $matchedPattern = $this->findBlockedPathPattern($args['path']);
        if ($matchedPattern === null) {
            return null;
        }

        $basename = basename($args['path']);

        return new PermissionResult(
            PermissionAction::Deny,
            "Cannot access '{$basename}' — matches blocked pattern '{$matchedPattern}'",
        );
    }

    /**
     * Find the first blocked path pattern that matches, or null.
     */
    private function findBlockedPathPattern(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path === '.') {
            return null;
        }

        // Check both raw path and resolved (symlink-followed) path
        $pathsToCheck = [$path];

        $resolved = PathResolver::resolve($path);
        if ($resolved !== null && $resolved !== $path) {
            $pathsToCheck[] = $resolved;
        }

        foreach ($this->blockedPaths as $pattern) {
            foreach ($pathsToCheck as $candidate) {
                $basename = basename($candidate);
                if (PermissionRule::matchesGlob($candidate, $pattern)
                    || PermissionRule::matchesGlob($basename, $pattern)) {
                    return $pattern;
                }
            }
        }

        return null;
    }
}
