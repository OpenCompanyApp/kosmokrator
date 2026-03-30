<?php

namespace Kosmokrator\Tool\Permission;

class PermissionEvaluator
{
    private PermissionMode $permissionMode = PermissionMode::Guardian;

    /**
     * @param PermissionRule[] $rules
     * @param string[] $blockedPaths Glob patterns for paths that should be denied
     */
    public function __construct(
        private readonly array $rules,
        private readonly SessionGrants $grants,
        private readonly array $blockedPaths = [],
        private readonly ?GuardianEvaluator $guardian = null,
    ) {}

    public function evaluate(string $toolName, array $args): PermissionResult
    {
        // Blocked paths — absolute deny, checked before everything
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

        if ($this->grants->isGranted($toolName)) {
            return new PermissionResult(PermissionAction::Allow);
        }

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

        return new PermissionResult(PermissionAction::Allow);
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

        $basename = basename($path);

        foreach ($this->blockedPaths as $pattern) {
            if (PermissionRule::matchesGlob($path, $pattern)
                || PermissionRule::matchesGlob($basename, $pattern)) {
                return $pattern;
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
}
