<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\PermissionRule;

/**
 * Evaluates permission rules against a tool call.
 *
 * Returns the result for Deny rules immediately (absolute denial). For Ask
 * results, returns null to delegate mode-specific handling to ModeOverrideCheck.
 */
class RuleCheck implements PermissionCheck
{
    /** @param PermissionRule[] $rules */
    public function __construct(
        private readonly array $rules,
    ) {}

    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        foreach ($this->rules as $rule) {
            $ruleResult = $rule->evaluate($toolName, $args);
            if ($ruleResult === null) {
                continue;
            }

            // Deny is absolute — halt the chain immediately
            if ($ruleResult->action === PermissionAction::Deny) {
                return $ruleResult;
            }

            // Ask is delegated to ModeOverrideCheck (next in chain)
            if ($ruleResult->action === PermissionAction::Ask) {
                return null;
            }

            // Any other action (e.g. Allow from a rule) — return as-is
            return new PermissionResult($ruleResult->action);
        }

        return null;
    }
}
