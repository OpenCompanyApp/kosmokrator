<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class KosmokratorLuaToolInvoker implements LuaToolInvoker
{
    private int $forceDepth = 0;

    public function __construct(
        private readonly ToolProviderRegistry $providers,
        private readonly CredentialResolver $credentials,
        private readonly IntegrationManager $integrationManager,
        private readonly PermissionEvaluator $permissions,
    ) {}

    public function invoke(string $toolSlug, array $args, ?string $account = null, bool $force = false): mixed
    {
        $provider = $this->assertCanInvoke($toolSlug, $force || $this->forceDepth > 0);

        $toolMeta = $provider->tools()[$toolSlug] ?? null;
        if ($toolMeta === null) {
            throw new \RuntimeException("Tool not found: {$toolSlug}");
        }

        // Create tool instance via provider, passing account context for multi-credential resolution
        $context = array_filter(['account' => $account]);
        $tool = $provider->createTool($toolMeta['class'], $context);

        // Execute and convert result
        $result = $tool->execute($args);

        if (! $result->succeeded()) {
            throw new \RuntimeException($result->error ?? "Tool failed: {$toolSlug}");
        }

        return $result->data;
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

    public function assertCanInvoke(string $toolSlug, bool $force = false): ToolProvider
    {
        $provider = $this->findProviderForTool($toolSlug);

        if ($provider === null) {
            throw new \RuntimeException("No provider found for tool: {$toolSlug}");
        }

        $appName = $provider->appName();
        $toolMeta = $provider->tools()[$toolSlug] ?? null;

        if ($toolMeta === null) {
            throw new \RuntimeException("Tool not found: {$toolSlug}");
        }

        $operation = $toolMeta['type'] ?? 'read';
        $permission = $this->integrationManager->getPermission($appName, $operation);

        if ($permission === 'deny' && ! $force) {
            throw new \RuntimeException("Integration '{$appName}' {$operation} access denied. Enable it in /settings → Integrations");
        }

        if ($permission === 'ask' && ! $force && $this->permissions->getPermissionMode() !== PermissionMode::Prometheus) {
            throw new \RuntimeException("Integration '{$appName}' {$operation} requires approval. Ask the user to change the permission in /settings → Integrations");
        }

        return $provider;
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
