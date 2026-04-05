<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

/**
 * Performs static heuristic analysis to decide if a tool call is safe enough
 * to auto-approve in Guardian mode — no LLM calls needed.
 *
 * Used by PermissionEvaluator when the active mode is Guardian. Also exposes
 * mutative-command detection used by Ask mode to enforce read-only bash.
 */
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
        'shell_read',
        'shell_kill',
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
     * @param  string[]  $safeCommandPatterns  Glob patterns for bash commands to auto-approve
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

        if ($toolName === 'shell_start') {
            return $this->isSafeCommand($args['command'] ?? '');
        }

        if ($toolName === 'shell_write') {
            return $this->isSafeCommand($args['input'] ?? '');
        }

        return false;
    }

    /**
     * Check whether the given path resolves inside the project root.
     */
    private function isInsideProject(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $resolved = PathResolver::resolve($path);
        if ($resolved === null) {
            return false;
        }

        return str_starts_with($resolved, $this->projectRoot.'/');
    }

    /**
     * Check whether the command matches a safe-command glob and contains no shell operators.
     */
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

    /**
     * Mutative command prefixes — commands that modify files, packages, or system state.
     * Used to block write operations in Ask mode.
     */
    private const MUTATIVE_PATTERNS = [
        'rm ', 'rm	', 'rmdir ',
        'mv ', 'cp ',
        'chmod ', 'chown ', 'chgrp ',
        'mkdir ', 'mkfifo ',
        'touch ',
        'ln ',
        'git commit', 'git push', 'git merge', 'git rebase', 'git reset', 'git checkout',
        'git stash', 'git cherry-pick', 'git revert', 'git tag', 'git branch -d',
        'git branch -D', 'git branch -m', 'git clean', 'git am', 'git apply',
        'npm install', 'npm ci', 'npm uninstall', 'npm update', 'npm publish',
        'npx ',
        'composer require', 'composer remove', 'composer update', 'composer install',
        'pip install', 'pip uninstall',
        'cargo install', 'cargo add', 'cargo remove',
        'apt ', 'apt-get ', 'brew ', 'yum ', 'dnf ', 'pacman ',
        'docker ', 'kubectl ',
        'kill ', 'killall ', 'pkill ',
        'dd ',
        'truncate ',
        'shred ',
        'tee ',
    ];

    /**
     * Shell operators that indicate potentially mutative constructs:
     * command chaining (;, &), output redirection (>), command substitution (` $),
     * and newlines (multiple commands). Pipes (|) and input redirection (<) are
     * excluded since they are inherently read-only.
     */
    private const MUTATIVE_SHELL_PATTERN = '/[;&`$>\n]/';

    /**
     * Redirections that are safe (non-mutative) — stderr to /dev/null and
     * stderr-to-stdout merges. These are commonly used in read-only commands.
     */
    private const SAFE_REDIRECTION_PATTERN = '/2>\/dev\/null|2>&1|2>&-/';

    /**
     * Check if a command is mutative (modifies files, packages, or system state).
     * Used to enforce read-only bash in Ask mode.
     */
    public function isMutativeCommand(string $command): bool
    {
        $command = trim($command);

        // Check each piped segment individually — pipes are read-only, but
        // individual segments can still be mutative (e.g. "cat f | tee out").
        $segments = explode('|', $command);

        foreach ($segments as $segment) {
            if ($this->segmentIsMutative(trim($segment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a single command segment (no pipes) is mutative.
     * Strips safe redirections (e.g. 2>/dev/null) before checking operators.
     */
    private function segmentIsMutative(string $segment): bool
    {
        // Strip safe redirections before checking for mutative operators
        $stripped = (string) preg_replace(self::SAFE_REDIRECTION_PATTERN, '', $segment);

        if ((bool) preg_match(self::MUTATIVE_SHELL_PATTERN, $stripped)) {
            return true;
        }

        return $this->segmentMatchesMutativePattern($segment);
    }

    /**
     * Check whether a single command segment (no pipes/chaining) matches
     * a known mutative command pattern.
     */
    private function segmentMatchesMutativePattern(string $segment): bool
    {
        $lower = strtolower($segment);
        foreach (self::MUTATIVE_PATTERNS as $pattern) {
            if (str_starts_with($lower, $pattern) || $lower === rtrim($pattern)) {
                return true;
            }
        }

        // Also check the first token's basename to catch full-path invocations
        // e.g. "/bin/rm foo" or "/usr/bin/git commit"
        $tokens = preg_split('/\s+/', $lower, 2);
        $base = basename($tokens[0] ?? '');
        $rest = ($tokens[1] ?? '') !== '' ? ' '.$tokens[1] : '';

        foreach (self::MUTATIVE_PATTERNS as $pattern) {
            if (str_starts_with($base.$rest, $pattern) || $base.$rest === rtrim($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the command text contains shell metacharacters (pipe, redirect, etc.).
     */
    private function containsShellOperators(string $command): bool
    {
        return (bool) preg_match(self::SHELL_META_PATTERN, $command);
    }

}
