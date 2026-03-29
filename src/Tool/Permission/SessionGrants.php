<?php

namespace Kosmokrator\Tool\Permission;

class SessionGrants
{
    /** @var array<string, true> */
    private array $grants = [];

    public function grant(string $toolName): void
    {
        $this->grants[$toolName] = true;
    }

    public function isGranted(string $toolName): bool
    {
        return isset($this->grants[$toolName]);
    }

    public function reset(): void
    {
        $this->grants = [];
    }
}
