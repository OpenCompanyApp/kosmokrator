<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionCheck;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\SessionGrants;

/**
 * Allows access when the tool has been previously granted in the current session.
 *
 * Session grants override Ask results but not Deny results (blocked paths and
 * deny patterns are checked earlier in the chain).
 */
class SessionGrantCheck implements PermissionCheck
{
    public function __construct(
        private readonly SessionGrants $grants,
    ) {}

    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        if ($this->grants->isGranted($toolName)) {
            return new PermissionResult(PermissionAction::Allow);
        }

        return null;
    }
}
