<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;

final class CompositeLuaToolInvoker implements LuaToolInvoker
{
    public function __construct(
        private readonly LuaToolInvoker $integrations,
        private readonly ?McpLuaToolInvoker $mcp = null,
    ) {}

    public function invoke(string $toolSlug, array $args, ?string $account = null): mixed
    {
        if (str_starts_with($toolSlug, 'mcp_') && $this->mcp !== null) {
            return $this->mcp->invoke($toolSlug, $args, $account);
        }

        return $this->integrations->invoke($toolSlug, $args, $account);
    }

    public function getToolMeta(string $toolSlug): array
    {
        if (str_starts_with($toolSlug, 'mcp_') && $this->mcp !== null) {
            return $this->mcp->getToolMeta($toolSlug);
        }

        return $this->integrations->getToolMeta($toolSlug);
    }
}
