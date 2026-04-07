<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class KosmokratorLuaToolInvoker implements LuaToolInvoker
{
    public function __construct(
        private readonly ToolProviderRegistry $providers,
        private readonly CredentialResolver $credentials,
        private readonly IntegrationManager $integrationManager,
    ) {}

    public function invoke(string $toolSlug, array $args, ?string $account = null): mixed
    {
        // 1. Find which provider owns this tool
        $provider = $this->findProviderForTool($toolSlug);

        if ($provider === null) {
            throw new \RuntimeException("No provider found for tool: {$toolSlug}");
        }

        $appName = $provider->appName();

        // 2. Check integration permissions
        $toolMeta = $provider->tools()[$toolSlug] ?? null;

        if ($toolMeta === null) {
            throw new \RuntimeException("Tool not found: {$toolSlug}");
        }

        $operation = $toolMeta['type'] ?? 'read';
        $permission = $this->integrationManager->getPermission($appName, $operation);

        if ($permission === 'deny') {
            throw new \RuntimeException("Integration '{$appName}' {$operation} access denied. Enable it in /settings → Integrations");
        }

        if ($permission === 'ask') {
            throw new \RuntimeException("Integration '{$appName}' {$operation} requires approval. Ask the user to change the permission in /settings → Integrations");
        }

        // 3. Create tool instance via provider, passing account context for multi-credential resolution
        $context = array_filter(['account' => $account]);
        $tool = $provider->createTool($toolMeta['class'], $context);

        // 4. Execute and convert result
        $result = $tool->execute($args);

        if (! $result->succeeded()) {
            throw new \RuntimeException($result->error ?? "Tool failed: {$toolSlug}");
        }

        return $result->data;
    }

    public function getToolMeta(string $toolSlug): array
    {
        $provider = $this->findProviderForTool($toolSlug);

        if ($provider === null) {
            return [];
        }

        $meta = $provider->tools()[$toolSlug] ?? [];

        return [
            'icon' => $meta['icon'] ?? 'ph:wrench',
            'name' => $meta['name'] ?? $toolSlug,
        ];
    }

    private function findProviderForTool(string $toolSlug): ?ToolProvider
    {
        foreach ($this->providers->all() as $provider) {
            if (isset($provider->tools()[$toolSlug])) {
                return $provider;
            }
        }

        return null;
    }
}
