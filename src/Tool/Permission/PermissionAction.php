<?php

namespace Kosmokrator\Tool\Permission;

/**
 * The tri-state outcome of a permission evaluation: allow, ask the user, or deny.
 *
 * Used as the actionable decision carried by PermissionResult.
 */
enum PermissionAction: string
{
    case Allow = 'allow';
    case Ask = 'ask';
    case Deny = 'deny';
}
