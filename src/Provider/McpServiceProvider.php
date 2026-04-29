<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpLuaToolInvoker;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Mcp\McpSecretStore;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(McpConfigStore::class, function (): McpConfigStore {
            $projectRoot = InstructionLoader::gitRoot() ?? getcwd() ?: null;
            $store = new McpConfigStore;
            $store->setProjectRoot($projectRoot);

            return $store;
        });
        $this->container->singleton(McpSecretStore::class);
        $this->container->singleton(McpClientManager::class);
        $this->container->singleton(McpCatalog::class);
        $this->container->singleton(McpPermissionEvaluator::class, function (): McpPermissionEvaluator {
            $projectRoot = InstructionLoader::gitRoot() ?? getcwd() ?: null;
            $settings = $this->container->make(SettingsManager::class);
            $settings->setProjectRoot($projectRoot);

            return new McpPermissionEvaluator(
                $settings,
                $this->container->bound(PermissionEvaluator::class) ? $this->container->make(PermissionEvaluator::class) : null,
            );
        });
        $this->container->singleton(McpLuaToolInvoker::class);
        $this->container->singleton(McpRuntime::class);
    }
}
