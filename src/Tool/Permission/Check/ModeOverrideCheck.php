<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\PermissionRule;

/**
 * Applies permission-mode logic (Guardian / Argus / Prometheus) to Ask results.
 *
 * Re-evaluates rules to find the first Ask match, then applies mode-specific
 * behavior: Prometheus auto-approves, Guardian delegates to heuristic analysis,
 * Argus always asks the user.
 */
class ModeOverrideCheck implements PermissionCheck
{
    /** @param PermissionRule[] $rules */
    public function __construct(
        private readonly array $rules,
        private readonly \Closure $modeResolver,
        private readonly ?GuardianEvaluator $guardian = null,
    ) {}

    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        // Find the first rule that would produce an Ask for this tool
        $hasAskRule = false;
        foreach ($this->rules as $rule) {
            $ruleResult = $rule->evaluate($toolName, $args);
            if ($ruleResult === null) {
                continue;
            }

            if ($ruleResult->action === PermissionAction::Ask) {
                $hasAskRule = true;

                break;
            }

            // Non-Ask match (Deny handled by RuleCheck, other actions too) — not our concern
            return null;
        }

        if (! $hasAskRule) {
            return null;
        }

        $mode = ($this->modeResolver)();

        // Prometheus: auto-approve everything
        if ($mode === PermissionMode::Prometheus) {
            return new PermissionResult(PermissionAction::Allow, autoApproved: true);
        }

        // Guardian: auto-approve if heuristics say safe
        if ($mode === PermissionMode::Guardian && $this->guardian !== null) {
            if ($this->guardian->shouldAutoApprove($toolName, $args)) {
                return new PermissionResult(PermissionAction::Allow, autoApproved: true);
            }
        }

        // Argus (or Guardian when not safe): ask user
        return new PermissionResult(PermissionAction::Ask);
    }
}
