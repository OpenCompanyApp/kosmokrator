<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

/**
 * Immutable value object representing the outcome of a permission evaluation.
 *
 * Carries the action (Allow / Ask / Deny), an optional human-readable reason,
 * and a flag indicating whether the allow was auto-approved by a heuristic.
 */
class PermissionResult
{
    public function __construct(
        public readonly PermissionAction $action,
        public readonly ?string $reason = null,
        public readonly bool $autoApproved = false,
    ) {}
}
