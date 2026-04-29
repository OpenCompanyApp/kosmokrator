<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;

final class McpLuaToolInvoker implements LuaToolInvoker
{
    private int $forceDepth = 0;

    public function __construct(
        private readonly McpConfigStore $configs,
        private readonly McpCatalog $catalog,
        private readonly McpClientManager $clients,
        private readonly McpPermissionEvaluator $permissions,
    ) {}

    public function invoke(string $toolSlug, array $args, ?string $account = null): mixed
    {
        $tool = $this->catalog->findBySlug($toolSlug);
        if ($tool === null) {
            throw new \RuntimeException("Unknown MCP tool: {$toolSlug}");
        }

        $server = $this->configs->get($tool->server);
        if ($server === null) {
            throw new \RuntimeException("Unknown MCP server: {$tool->server}");
        }

        $this->permissions->assertAllowed($server, $tool, $this->forceDepth > 0);

        return McpRuntime::normalizeToolResult($this->clients->client($tool->server)->callTool($tool->name, $args));
    }

    public function getToolMeta(string $toolSlug): array
    {
        $tool = $this->catalog->findBySlug($toolSlug);

        return [
            'icon' => 'ph:plugs-connected',
            'name' => $tool === null ? $toolSlug : "{$tool->server}.{$tool->luaName}",
        ];
    }

    public function runWithForce(bool $force, \Closure $callback): mixed
    {
        if (! $force) {
            return $callback();
        }

        $this->forceDepth++;
        try {
            return $callback();
        } finally {
            $this->forceDepth--;
        }
    }
}
