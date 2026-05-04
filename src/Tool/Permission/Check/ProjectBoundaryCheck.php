<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\PathResolver;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\PermissionResult;

/**
 * Prompts the user before file tools access paths outside the project root.
 *
 * Positioned before SessionGrantCheck so a session-wide tool grant cannot
 * silently approve newly requested paths outside the project root. Prometheus
 * intentionally bypasses this prompt and lets ModeOverrideCheck auto-approve.
 * In Guardian and Argus modes, outside-project paths trigger an Ask prompt so
 * the user can approve or deny.
 */
class ProjectBoundaryCheck implements PermissionCheck
{
    private const BOUNDED_TOOLS = [
        'file_write', 'file_edit', 'file_read', 'glob', 'grep',
    ];

    private readonly string $resolvedProjectRoot;

    /** @var string[] */
    private readonly array $resolvedAllowedPaths;

    /**
     * @param  string  $projectRoot  Absolute path to the project root
     * @param  string[]  $allowedPaths  Additional allowed path prefixes (resolved at construction)
     * @param  \Closure(): PermissionMode  $modeResolver  Returns the current permission mode
     */
    public function __construct(
        string $projectRoot,
        array $allowedPaths,
        private readonly \Closure $modeResolver,
    ) {
        // Resolve symlinks at construction time to match PathResolver behavior
        $this->resolvedProjectRoot = realpath($projectRoot) ?: $projectRoot;
        $this->resolvedAllowedPaths = array_filter(array_map(
            fn (string $p) => realpath($p) ?: $p,
            $allowedPaths,
        ));
    }

    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        if (! in_array($toolName, self::BOUNDED_TOOLS, true)) {
            return null;
        }

        $path = $args['path'] ?? '';
        if ($path === '') {
            return null;
        }

        if (self::pathWithinBoundary($path, $this->resolvedProjectRoot, $this->resolvedAllowedPaths)) {
            return null;
        }

        if (($this->modeResolver)() === PermissionMode::Prometheus) {
            return null;
        }

        return new PermissionResult(
            PermissionAction::Ask,
            "Path '".basename($path)."' is outside the project root. Allow?",
        );
    }

    /**
     * Check whether a path resolves within the project root or an allowed path.
     *
     * @param  string  $path  Raw path to check
     * @param  string  $projectRoot  Absolute project root
     * @param  string[]  $allowedPaths  Additional allowed path prefixes
     */
    public static function pathWithinBoundary(string $path, string $projectRoot, array $allowedPaths = []): bool
    {
        $resolved = PathResolver::resolve($path);

        // If PathResolver can't resolve (deeply nested new path), walk up the
        // directory tree until we find an existing ancestor to resolve.
        if ($resolved === null) {
            $resolved = PathResolver::resolveViaExistingAncestor($path);
        }

        if ($resolved === null) {
            return false;
        }

        if (str_starts_with($resolved, $projectRoot.'/') || $resolved === $projectRoot) {
            return true;
        }

        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($resolved, $allowed.'/') || $resolved === $allowed) {
                return true;
            }
        }

        return false;
    }
}
