<?php

namespace Kosmokrator\Tool\Permission;

/**
 * Central permission decision engine: evaluates every tool call against rules,
 * blocked paths, session grants, and the active PermissionMode to produce a
 * PermissionResult (Allow / Ask / Deny).
 *
 * Lives in the tool-call hot path — called before every tool execution.
 */
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

    /**
     * Run the full evaluation pipeline for a tool call and return the final decision.
     *
     * @param  string  $toolName  Name of the tool being called
     * @param  array   $args      Named arguments passed to the tool
     */
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

            $command = trim((string) ($args['command'] ?? $args['input'] ?? ''));
            if ($command === '') {
                continue;
            }
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

    /** Switch the active permission mode at runtime. */
    public function setPermissionMode(PermissionMode $mode): void
    {
        $this->permissionMode = $mode;
    }

    /** Return the currently active permission mode. */
    public function getPermissionMode(): PermissionMode
    {
        return $this->permissionMode;
    }

    /** Mark a tool as session-approved so future calls skip the Ask prompt. */
    public function grantSession(string $toolName): void
    {
        $this->grants->grant($toolName);
    }

    /** Clear all session grants. */
    public function resetGrants(): void
    {
        $this->grants->reset();
    }

    /** Delegate mutative-command check to the Guardian evaluator. */
    public function isMutativeCommand(string $command): bool
    {
        return $this->guardian?->isMutativeCommand($command) ?? false;
    }
}
