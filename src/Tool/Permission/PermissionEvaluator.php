<?php

namespace Kosmokrator\Tool\Permission;

class PermissionEvaluator
{
    private bool $autoApprove = false;

    /**
     * @param PermissionRule[] $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly SessionGrants $grants,
    ) {}

    public function evaluate(string $toolName, array $args): PermissionAction
    {
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
