<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Integration\Runtime;

use Illuminate\Config\Repository;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\Integration\Runtime\IntegrationDocService;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\Runtime\IntegrationRuntimeOptions;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\LuaSandboxService;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\SessionGrants;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Lua\LuaDocRenderer;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use OpenCompany\IntegrationCore\Support\ToolResult;
use OpenCompany\Integrations\Plane\PlaneToolProvider;
use PHPUnit\Framework\TestCase;

final class PlaneIntegrationRuntimeTest extends TestCase
{
    public function test_real_plane_provider_is_discoverable_with_hydrated_schema_and_docs(): void
    {
        [$catalog] = $this->buildRuntime(new PlaneToolProvider);

        $functions = $catalog->functions();

        $this->assertCount(33, array_filter(
            array_keys($functions),
            static fn (string $name): bool => str_starts_with($name, 'plane.'),
        ));
        $this->assertArrayHasKey('plane.create_issue', $functions);
        $this->assertArrayHasKey('plane.list_workspaces', $functions);
        $this->assertArrayHasKey('plane.list_comments', $functions);

        $createIssue = $catalog->hydrate($functions['plane.create_issue']);

        $this->assertSame('plane_create_issue', $createIssue->slug);
        $this->assertSame('write', $createIssue->operation);
        $this->assertSame(['project_id', 'name'], $createIssue->requiredParameters());
        $this->assertSame('array', $createIssue->inputSchema()['properties']['labels']['type']);
        $this->assertSame('string', $createIssue->inputSchema()['properties']['priority']['type']);

        $docs = new IntegrationDocService($catalog);
        $text = $docs->render('plane.create_issue');

        $this->assertStringContainsString('kosmokrator integrations:plane create_issue --project-id=123 --name=value', $text);
        $this->assertStringContainsString('kosmokrator integrations:call plane.create_issue --project-id=123 --name=value', $text);
        $this->assertStringContainsString('local result = app.integrations.plane.create_issue', $text);

        $json = json_decode($docs->render('plane.create_issue', 'json'), true);

        $this->assertSame('plane.create_issue', $json['name']);
        $this->assertSame(['project_id', 'name'], $json['input_schema']['required']);
    }

    public function test_runtime_validates_required_plane_write_parameters_before_invocation(): void
    {
        $provider = new RuntimeFakePlaneProvider;
        [, $runtime] = $this->buildRuntime($provider);
        RuntimeFakePlaneCreateIssue::$executions = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required parameter(s): project_id, name');

        try {
            $runtime->call('plane.create_issue', []);
        } finally {
            $this->assertSame(0, RuntimeFakePlaneCreateIssue::$executions);
        }
    }

    public function test_runtime_executes_plane_read_functions_and_preserves_account_context(): void
    {
        $provider = new RuntimeFakePlaneProvider;
        [, $runtime] = $this->buildRuntime($provider, accounts: ['work']);

        $result = $runtime->call('plane.list_workspaces', [], 'work');

        $this->assertTrue($result->success);
        $this->assertSame('plane.list_workspaces', $result->function);
        $this->assertSame(['workspaces' => [['slug' => 'kosmokrator']], 'count' => 1], $result->data);
        $this->assertSame(['account' => 'work'], $provider->lastContext);
    }

    public function test_runtime_validates_credentials_for_selected_account(): void
    {
        $provider = new RuntimeFakePlaneProvider;
        [, $runtime] = $this->buildRuntime($provider, accounts: ['work']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing required credentials for account 'missing'");

        $runtime->call('plane.list_workspaces', [], 'missing');
    }

    public function test_runtime_rejects_discoverable_providers_without_cli_runtime_support(): void
    {
        [, $runtime] = $this->buildRuntime(new RuntimeOauthPlaneProvider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported by the local CLI runtime yet');

        $runtime->call('plane.list_workspaces', []);
    }

    public function test_runtime_follows_integration_write_permissions_unless_forced(): void
    {
        $provider = new RuntimeFakePlaneProvider;
        [, $runtime] = $this->buildRuntime($provider, writePermission: 'ask');
        RuntimeFakePlaneCreateIssue::$executions = 0;

        $blocked = $runtime->call('plane.create_issue', ['project_id' => 'p1', 'name' => 'Blocked']);

        $this->assertFalse($blocked->success);
        $this->assertStringContainsString('requires approval', (string) $blocked->error);
        $this->assertSame(0, RuntimeFakePlaneCreateIssue::$executions);

        $forced = $runtime->call(
            'plane.create_issue',
            ['project_id' => 'p1', 'name' => 'Forced'],
            options: new IntegrationRuntimeOptions(force: true),
        );

        $this->assertTrue($forced->success);
        $this->assertTrue($forced->meta['permission_bypassed']);
        $this->assertSame(1, RuntimeFakePlaneCreateIssue::$executions);
    }

    public function test_runtime_dry_run_validates_without_executing_provider_tool(): void
    {
        $provider = new RuntimeFakePlaneProvider;
        [, $runtime] = $this->buildRuntime($provider, writePermission: 'allow');
        RuntimeFakePlaneCreateIssue::$executions = 0;
        $provider->createToolCalls = 0;

        $result = $runtime->call(
            'plane.create_issue',
            ['project_id' => 'p1', 'name' => 'Dry run'],
            options: new IntegrationRuntimeOptions(dryRun: true),
        );

        $this->assertTrue($result->success);
        $this->assertTrue($result->meta['dry_run']);
        $this->assertSame(0, RuntimeFakePlaneCreateIssue::$executions);
    }

    public function test_lua_docs_helpers_can_discover_plane_functions_without_calling_api(): void
    {
        [, $runtime] = $this->buildRuntime(new PlaneToolProvider);

        $result = $runtime->executeLua('print(string.find(docs.read("plane.create_issue"), "plane.create_issue") ~= nil)');

        $this->assertNull($result->lua->error);
        $this->assertSame('true', trim($result->lua->output));
        $this->assertSame([], $result->callLog);
    }

    /**
     * @return array{IntegrationCatalog, IntegrationRuntime}
     */
    private function buildRuntime(ToolProvider $provider, array $accounts = [], string $writePermission = 'allow'): array
    {
        $registry = new ToolProviderRegistry;
        $registry->register($provider);

        $settings = new SettingsManager(
            config: new Repository([]),
            schema: new SettingsSchema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settings->setRaw('integrations.plane.enabled', true, 'global');
        $settings->setRaw('integrations.plane.permissions.read', 'allow', 'global');
        $settings->setRaw('integrations.plane.permissions.write', $writePermission, 'global');

        $defaultCredentials = [
            'api_key' => 'test-api-key',
            'url' => 'https://api.plane.so',
            'workspace_slug' => 'kosmokrator',
        ];
        $accountCredentials = array_fill_keys($accounts, $defaultCredentials);

        $credentials = new RuntimeFakeCredentialResolver(
            values: ['plane' => $defaultCredentials + ['accounts' => $accountCredentials]],
            accounts: ['plane' => $accounts],
        );

        $manager = new IntegrationManager($registry, $settings, $credentials);
        $catalogBuilder = new LuaCatalogBuilder;
        $catalog = new IntegrationCatalog($registry, $manager, $catalogBuilder);
        $docs = new IntegrationDocService($catalog);
        $luaDocs = new LuaDocService($registry, $manager, $catalogBuilder, new LuaDocRenderer);
        $invoker = new KosmokratorLuaToolInvoker(
            $registry,
            $credentials,
            $manager,
            new PermissionEvaluator([], new SessionGrants),
        );

        return [
            $catalog,
            new IntegrationRuntime(
                $catalog,
                $manager,
                new LuaSandboxService,
                $luaDocs,
                $docs,
                $invoker,
            ),
        ];
    }
}

final class RuntimeFakeCredentialResolver implements CredentialResolver
{
    /**
     * @param  array<string, array<string, mixed>>  $values
     * @param  array<string, list<string>>  $accounts
     */
    public function __construct(
        private readonly array $values,
        private readonly array $accounts = [],
    ) {}

    public function get(string $integration, string $key, mixed $default = null, ?string $account = null): mixed
    {
        if ($account !== null) {
            return $this->values[$integration]['accounts'][$account][$key] ?? $default;
        }

        return $this->values[$integration][$key] ?? $default;
    }

    public function isConfigured(string $integration, ?string $account = null): bool
    {
        return isset($this->values[$integration]['api_key']) && $this->values[$integration]['api_key'] !== '';
    }

    public function getAccounts(string $integration): array
    {
        return $this->accounts[$integration] ?? [];
    }
}

class RuntimeFakePlaneProvider implements ToolProvider
{
    public int $createToolCalls = 0;

    /** @var array<string, mixed> */
    public array $lastContext = [];

    public function appName(): string
    {
        return 'plane';
    }

    public function appMeta(): array
    {
        return [
            'label' => 'Plane',
            'description' => 'Project management',
            'icon' => 'ph:kanban',
        ];
    }

    public function tools(): array
    {
        return [
            'plane_list_workspaces' => [
                'class' => RuntimeFakePlaneListWorkspaces::class,
                'type' => 'read',
                'name' => 'List Workspaces',
                'description' => 'List Plane workspaces.',
                'icon' => 'ph:buildings',
            ],
            'plane_create_issue' => [
                'class' => RuntimeFakePlaneCreateIssue::class,
                'type' => 'write',
                'name' => 'Create Issue',
                'description' => 'Create a Plane issue.',
                'icon' => 'ph:plus-circle',
            ],
        ];
    }

    public function isIntegration(): bool
    {
        return true;
    }

    public function createTool(string $class, array $context = []): Tool
    {
        $this->createToolCalls++;
        $this->lastContext = $context;

        return new $class;
    }

    public function luaDocsPath(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'type' => 'secret', 'label' => 'API Key', 'required' => true],
        ];
    }
}

final class RuntimeOauthPlaneProvider extends RuntimeFakePlaneProvider
{
    public function credentialFields(): array
    {
        return [
            ['key' => 'oauth', 'type' => 'oauth_connect', 'label' => 'Connect Plane', 'required' => true],
        ];
    }
}

final class RuntimeFakePlaneListWorkspaces implements Tool
{
    public function name(): string
    {
        return 'plane_list_workspaces';
    }

    public function description(): string
    {
        return 'List all Plane workspaces.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success(['workspaces' => [['slug' => 'kosmokrator']], 'count' => 1]);
    }
}

final class RuntimeFakePlaneCreateIssue implements Tool
{
    public static int $executions = 0;

    public function name(): string
    {
        return 'plane_create_issue';
    }

    public function description(): string
    {
        return 'Create a Plane issue.';
    }

    public function parameters(): array
    {
        return [
            'project_id' => ['type' => 'string', 'required' => true, 'description' => 'Project UUID.'],
            'name' => ['type' => 'string', 'required' => true, 'description' => 'Issue title.'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        self::$executions++;

        return ToolResult::success(['id' => 'issue-1', 'name' => $args['name']]);
    }
}
