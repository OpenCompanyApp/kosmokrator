<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

class PermissionResult
{
    public function __construct(
        public readonly PermissionAction $action,
        public readonly ?string $reason = null,
        public readonly bool $autoApproved = false,
    ) {}
}
