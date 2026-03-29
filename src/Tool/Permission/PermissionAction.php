<?php

namespace Kosmokrator\Tool\Permission;

enum PermissionAction: string
{
    case Allow = 'allow';
    case Ask = 'ask';
    case Deny = 'deny';
}
