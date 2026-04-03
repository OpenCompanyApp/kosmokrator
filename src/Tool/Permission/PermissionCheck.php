<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

/**
 * A single stage in the permission evaluation chain.
 *
 * Implementations inspect one aspect of a tool call (blocked paths, deny
 * patterns, session grants, rules, mode overrides) and either return a
 * PermissionResult to halt the chain or null to pass to the next check.
 */
interface PermissionCheck
{
    /**
     * Evaluate permission for a tool call.
     * Return a PermissionResult to halt the chain, or null to pass to the next check.
     */
    public function evaluate(string $toolName, array $args): ?PermissionResult;
}
