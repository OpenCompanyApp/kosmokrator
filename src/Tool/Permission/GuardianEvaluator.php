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
        'lua_list_docs',
        'lua_search_docs',
        'lua_read_doc',
    ];

    /**
     * Shell control operators that make a command unsafe for Guardian auto-approval.
     * This check is applied while tokenizing so operators hidden inside quoted
     * literal arguments do not affect command structure.
     */
    private const SHELL_CONTROL_CHARS = ";&|`$><()\n\r";

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

        if (PathResolver::containsSymlinkComponent($path, $this->projectRoot)) {
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

        $tokens = $this->tokenizeSimpleCommand($command);
        if ($tokens === null) {
            return false;
        }

        if ($this->isMutativeCommand($command)) {
            return false;
        }

        foreach ($this->safeCommandPatterns as $pattern) {
            if ($this->tokensMatchSafePattern($tokens, $pattern)) {
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
        'git stash push', 'git stash save', 'git stash pop', 'git stash apply',
        'git stash drop', 'git stash clear', 'git stash branch', 'git stash store',
        'git cherry-pick', 'git revert', 'git tag', 'git branch -d',
        'git branch -D', 'git branch -m', 'git clean', 'git am', 'git apply',
        'npm install', 'npm ci', 'npm uninstall', 'npm update', 'npm publish',
        'npm i ', 'npm add ', 'npm rm ', 'npm remove ', 'npm un ', 'npm audit fix',
        'npm run ', 'npm test', 'npm exec ', 'npm x ', 'npm create ', 'npm init',
        'npm pack', 'npm link', 'npm version', 'npm rebuild', 'npm dedupe', 'npm prune',
        'pnpm install', 'pnpm add', 'pnpm remove', 'pnpm update', 'pnpm publish',
        'pnpm run ', 'pnpm test', 'pnpm exec ', 'pnpm dlx ', 'pnpm create ',
        'yarn install', 'yarn add', 'yarn remove', 'yarn upgrade', 'yarn publish',
        'yarn run ', 'yarn test', 'yarn dlx ', 'yarn create ',
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
        $stripped = $this->stripQuotedLiterals($stripped);
        if ($stripped === null) {
            return true;
        }

        if ((bool) preg_match(self::MUTATIVE_SHELL_PATTERN, $stripped)) {
            return true;
        }

        return $this->segmentMatchesMutativePattern($segment);
    }

    private function stripQuotedLiterals(string $segment): ?string
    {
        $result = '';
        $quote = null;
        $escaped = false;
        $length = strlen($segment);

        for ($i = 0; $i < $length; $i++) {
            $char = $segment[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                if ($quote === null) {
                    $result .= ' ';
                }

                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                    $result .= ' ';
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $result .= ' ';

                continue;
            }

            $result .= $char;
        }

        return $quote === null && ! $escaped ? $result : null;
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
     * Tokenize a single simple shell command. Returns null for syntax that can
     * alter shell control flow or perform expansion/substitution.
     *
     * @return list<string>|null
     */
    private function tokenizeSimpleCommand(string $command): ?array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $escaped = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escaped) {
                if ($char === "\n" || $char === "\r") {
                    return null;
                }
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if (str_contains("$`\n\r", $char)) {
                    return null;
                }

                $current .= $char;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }
            if (str_contains(self::SHELL_CONTROL_CHARS, $char)) {
                return null;
            }
            if (ctype_space($char)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $char;
        }

        if ($escaped || $quote !== null) {
            return null;
        }
        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens === [] ? null : $tokens;
    }

    /**
     * Match parsed argv tokens against a configured safe command pattern.
     * A wildcard in the final pattern token may cover the rest of argv, keeping
     * patterns like "git *" and "php vendor/bin/phpunit*" ergonomic.
     *
     * @param  list<string>  $tokens
     */
    private function tokensMatchSafePattern(array $tokens, string $pattern): bool
    {
        $patternTokens = $this->tokenizeSimpleCommand($pattern);
        if ($patternTokens === null) {
            return false;
        }

        $lastIndex = count($patternTokens) - 1;
        foreach ($patternTokens as $index => $patternToken) {
            $isLast = $index === $lastIndex;
            $hasWildcard = str_contains($patternToken, '*');

            if (! isset($tokens[$index])) {
                return $isLast && $patternToken === '*';
            }
            if (! PermissionRule::matchesGlob($tokens[$index], $patternToken)) {
                return false;
            }
            if ($isLast && $hasWildcard) {
                return true;
            }
        }

        return count($tokens) === count($patternTokens);
    }
}
