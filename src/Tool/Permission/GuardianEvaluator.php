<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

class GuardianEvaluator
{
    private const ALWAYS_SAFE = [
        'file_read',
        'glob',
        'grep',
        'task_create',
        'task_update',
        'task_list',
        'task_get',
        'memory_save',
        'memory_search',
    ];

    /**
     * Shell metacharacters that indicate chaining, piping, redirection,
     * or command substitution. Presence of ANY of these means the command
     * is not provably safe by static analysis.
     */
    private const SHELL_META_PATTERN = '/[;&|`$><\n]/';

    /**
     * @param string[] $safeCommandPatterns Glob patterns for bash commands to auto-approve
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $safeCommandPatterns = [],
    ) {}

    /**
     * Determine if a tool call is safe to auto-approve in Guardian mode.
     * Pure static analysis — no LLM calls.
     */
    public function shouldAutoApprove(string $toolName, array $args): bool
    {
        if (in_array($toolName, self::ALWAYS_SAFE, true)) {
            return true;
        }

        if ($toolName === 'file_write' || $toolName === 'file_edit') {
            return $this->isInsideProject($args['path'] ?? '');
        }

        if ($toolName === 'bash') {
            return $this->isSafeCommand($args['command'] ?? '');
        }

        return false;
    }

    private function isInsideProject(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Resolve to absolute path, handling symlinks
        $resolved = realpath($path);

        if ($resolved === false) {
            // File doesn't exist yet (file_write) — resolve parent directory
            $parent = realpath(dirname($path));
            if ($parent === false) {
                return false;
            }
            $resolved = $parent . '/' . basename($path);
        }

        return str_starts_with($resolved, $this->projectRoot . '/');
    }

    private function isSafeCommand(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }

        if ($this->containsShellOperators($command)) {
            return false;
        }

        foreach ($this->safeCommandPatterns as $pattern) {
            if (PermissionRule::matchesGlob($command, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function containsShellOperators(string $command): bool
    {
        return (bool) preg_match(self::SHELL_META_PATTERN, $command);
    }
}
