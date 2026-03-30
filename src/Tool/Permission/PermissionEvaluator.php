<?php

namespace Kosmokrator\Tool\Permission;

class PermissionEvaluator
{
    private bool $autoApprove = false;

    /**
     * @param PermissionRule[] $rules
     * @param string[] $blockedPaths Glob patterns for paths that should be denied
     */
    public function __construct(
        private readonly array $rules,
        private readonly SessionGrants $grants,
        private readonly array $blockedPaths = [],
    ) {}

    public function evaluate(string $toolName, array $args): PermissionAction
    {
        // Blocked paths — absolute deny, checked before session grants
        if ($this->blockedPaths !== [] && isset($args['path'])) {
            if ($this->isPathBlocked($args['path'])) {
                return PermissionAction::Deny;
            }
        }

        if ($this->grants->isGranted($toolName)) {
            return PermissionAction::Allow;
        }

        foreach ($this->rules as $rule) {
            $action = $rule->evaluate($toolName, $args);
            if ($action !== null) {
                // Auto-approve overrides Ask but never overrides Deny
                if ($action === PermissionAction::Ask && $this->autoApprove) {
                    return PermissionAction::Allow;
                }

                return $action;
            }
        }

        return PermissionAction::Allow;
    }

    private function isPathBlocked(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || $path === '.') {
            return false;
        }

        $basename = basename($path);

        foreach ($this->blockedPaths as $pattern) {
            if ($this->matchesPathPattern($path, $pattern)
                || $this->matchesPathPattern($basename, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPathPattern(string $value, string $pattern): bool
    {
        $regex = '/^' . str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            preg_quote($pattern, '/'),
        ) . '$/i';

        return (bool) preg_match($regex, $value);
    }

    public function setAutoApprove(bool $enabled): void
    {
        $this->autoApprove = $enabled;
    }

    public function isAutoApprove(): bool
    {
        return $this->autoApprove;
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
