<?php

namespace Kosmokrator\Tool\Permission;

class PermissionEvaluator
{
    private PermissionMode $permissionMode = PermissionMode::Guardian;

    /**
     * @param  PermissionRule[]  $rules
     * @param  string[]  $blockedPaths  Glob patterns for paths that should be denied
     */
    public function __construct(
        private readonly array $rules,
        private readonly SessionGrants $grants,
        private readonly array $blockedPaths = [],
        private readonly ?GuardianEvaluator $guardian = null,
    ) {}

    public function evaluate(string $toolName, array $args): PermissionResult
    {
        // 1. Blocked paths — absolute deny, checked before everything
        if ($this->blockedPaths !== [] && isset($args['path'])) {
            $matchedPattern = $this->findBlockedPathPattern($args['path']);
            if ($matchedPattern !== null) {
                $basename = basename($args['path']);

                return new PermissionResult(
                    PermissionAction::Deny,
                    "Cannot access '{$basename}' — matches blocked pattern '{$matchedPattern}'",
                );
            }
        }

        // 2. Deny patterns — absolute deny, checked before session grants
        $denyResult = $this->checkDenyPatterns($toolName, $args);
        if ($denyResult !== null) {
            return $denyResult;
        }

        // 3. Session grants — allow if previously granted (overrides Ask, but not Deny)
        if ($this->grants->isGranted($toolName)) {
            return new PermissionResult(PermissionAction::Allow);
        }

        // 4. Rule evaluation (Ask/Deny actions)
        foreach ($this->rules as $rule) {
            $ruleResult = $rule->evaluate($toolName, $args);
            if ($ruleResult === null) {
                continue;
            }

            // Deny is absolute — no mode overrides it
            if ($ruleResult->action === PermissionAction::Deny) {
                return $ruleResult;
            }

            if ($ruleResult->action === PermissionAction::Ask) {
                // Prometheus: auto-approve everything
                if ($this->permissionMode === PermissionMode::Prometheus) {
                    return new PermissionResult(PermissionAction::Allow, autoApproved: true);
                }

                // Guardian: auto-approve if heuristics say safe
                if ($this->permissionMode === PermissionMode::Guardian && $this->guardian !== null) {
                    if ($this->guardian->shouldAutoApprove($toolName, $args)) {
                        return new PermissionResult(PermissionAction::Allow, autoApproved: true);
                    }
                }

                // Argus (or Guardian when not safe): ask user
                return new PermissionResult(PermissionAction::Ask);
            }

            return new PermissionResult($ruleResult->action);
        }

        // 5. No matching rule — allow
        return new PermissionResult(PermissionAction::Allow);
    }

    /**
     * Check deny patterns across all matching rules.
     * Returns a Deny result if any rule's deny pattern matches, null otherwise.
     */
    private function checkDenyPatterns(string $toolName, array $args): ?PermissionResult
    {
        foreach ($this->rules as $rule) {
            if ($rule->toolName !== $toolName || $rule->denyPatterns === []) {
                continue;
            }

            if (! isset($args['command'])) {
                continue;
            }

            $command = trim((string) $args['command']);
            foreach ($rule->denyPatterns as $pattern) {
                if (PermissionRule::matchesGlob($command, $pattern)) {
                    return new PermissionResult(
                        PermissionAction::Deny,
                        "Command matches blocked pattern '{$pattern}'",
                    );
                }
            }
        }

        return null;
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

        $resolved = realpath($path);
        if ($resolved === false) {
            // File doesn't exist yet — resolve parent directory
            $parentResolved = realpath(dirname($path));
            if ($parentResolved !== false) {
                $resolved = $parentResolved.'/'.basename($path);
            }
        }
        if ($resolved !== false && $resolved !== $path) {
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

    public function setPermissionMode(PermissionMode $mode): void
    {
        $this->permissionMode = $mode;
    }

    public function getPermissionMode(): PermissionMode
    {
        return $this->permissionMode;
    }

    public function grantSession(string $toolName): void
    {
        $this->grants->grant($toolName);
    }

    public function resetGrants(): void
    {
        $this->grants->reset();
    }

    public function isMutativeCommand(string $command): bool
    {
        return $this->guardian?->isMutativeCommand($command) ?? false;
    }
}
