<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Integration;

use Illuminate\Config\Repository;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\SessionGrants;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use OpenCompany\IntegrationCore\Support\ToolResult;
use PHPUnit\Framework\TestCase;

final class KosmokratorLuaToolInvokerTest extends TestCase
{
    public function test_prometheus_mode_auto_allows_integration_permission_ask(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register(new class implements ToolProvider
        {
            public function appName(): string
            {
                return 'plane';
            }

            public function appMeta(): array
            {
                return ['label' => 'Plane', 'description' => 'Plane integration', 'icon' => 'ph:kanban'];
            }

            public function tools(): array
            {
                return [
                    'plane_create_issue' => [
                        'class' => FakeWriteTool::class,
                        'type' => 'write',
                        'name' => 'Create Issue',
                        'description' => 'Create issue.',
                    ],
                ];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                return new FakeWriteTool;
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [];
            }
        });

        $settings = new SettingsManager(
            config: new Repository([]),
            schema: new SettingsSchema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settings->setRaw('integrations.plane.permissions.write', 'ask', 'global');

        $credentials = $this->createStub(CredentialResolver::class);
        $integrationManager = new IntegrationManager($registry, $settings, $credentials);

        $permissions = new PermissionEvaluator([], new SessionGrants);
        $permissions->setPermissionMode(PermissionMode::Prometheus);

        $invoker = new KosmokratorLuaToolInvoker($registry, $credentials, $integrationManager, $permissions);

        $result = $invoker->invoke('plane_create_issue', ['name' => 'Test']);

        $this->assertSame(['created' => true], $result);
    }

    public function test_non_prometheus_mode_still_blocks_integration_permission_ask(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register(new class implements ToolProvider
        {
            public function appName(): string
            {
                return 'plane';
            }

            public function appMeta(): array
            {
                return ['label' => 'Plane', 'description' => 'Plane integration', 'icon' => 'ph:kanban'];
            }

            public function tools(): array
            {
                return [
                    'plane_create_issue' => [
                        'class' => FakeWriteTool::class,
                        'type' => 'write',
                        'name' => 'Create Issue',
                        'description' => 'Create issue.',
                    ],
                ];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                return new FakeWriteTool;
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [];
            }
        });

        $settings = new SettingsManager(
            config: new Repository([]),
            schema: new SettingsSchema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settings->setRaw('integrations.plane.permissions.write', 'ask', 'global');

        $credentials = $this->createStub(CredentialResolver::class);
        $integrationManager = new IntegrationManager($registry, $settings, $credentials);

        $permissions = new PermissionEvaluator([], new SessionGrants);
        $permissions->setPermissionMode(PermissionMode::Guardian);

        $invoker = new KosmokratorLuaToolInvoker($registry, $credentials, $integrationManager, $permissions);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Integration 'plane' write requires approval");

        $invoker->invoke('plane_create_issue', ['name' => 'Test']);
    }
}

final class FakeWriteTool implements Tool
{
    public function name(): string
    {
        return 'plane_create_issue';
    }

    public function description(): string
    {
        return 'Create issue.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success(['created' => true]);
    }
}
