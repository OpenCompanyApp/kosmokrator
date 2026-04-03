<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\PermissionRule;

/**
 * Denies access when a tool call's command/input argument matches a deny
 * pattern from the permission rules.
 *
 * Checked before session grants — deny patterns are absolute and cannot be
 * overridden by grants or permission modes.
 */
class DenyPatternCheck implements PermissionCheck
{
    /** @param PermissionRule[] $rules */
    public function __construct(
        private readonly array $rules,
    ) {}

    public function evaluate(string $toolName, array $args): ?PermissionResult
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
}
