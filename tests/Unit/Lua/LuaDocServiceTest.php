<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Lua;

use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\Tool\ToolResult;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Lua\LuaDocRenderer;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use PHPUnit\Framework\TestCase;

class LuaDocServiceTest extends TestCase
{
    public function test_namespace_summary_lists_available_integrations_even_when_none_are_active(): void
    {
        $manager = $this->createStub(IntegrationManager::class);
        $manager->method('getActiveProviders')->willReturn([]);
        $manager->method('getLocallyRunnableProviders')->willReturn([
            'plane' => $this->fakeProvider('plane'),
            'plausible' => $this->fakeProvider('plausible'),
            'coingecko' => $this->fakeProvider('coingecko'),
        ]);

        $service = new LuaDocService(
            new ToolProviderRegistry,
            $manager,
            new LuaCatalogBuilder,
            new LuaDocRenderer,
        );

        $summary = $service->getNamespaceSummary();

        $this->assertStringContainsString('app.integrations.*', $summary);
        $this->assertStringContainsString("Active now:\n    none", $summary);
        $this->assertStringContainsString('app.integrations.coingecko', $summary);
        $this->assertStringContainsString('app.integrations.plane', $summary);
        $this->assertStringContainsString('app.integrations.plausible', $summary);
        $this->assertStringContainsString('app.tools.*', $summary);
    }

    public function test_list_docs_is_concise_when_no_integrations_are_active(): void
    {
        $service = $this->makeService(
            active: [],
            runnable: ['plane', 'plausible', 'coingecko'],
        );

        $docs = $service->listDocs();

        $this->assertStringContainsString('No active Lua integration namespaces are available in this session.', $docs);
        $this->assertStringContainsString('Installed but inactive integrations: 3.', $docs);
        $this->assertStringContainsString('Examples: app.integrations.coingecko, app.integrations.plane, app.integrations.plausible', $docs);
        $this->assertStringNotContainsString('Available Lua API namespaces:', $docs);
    }

    public function test_list_docs_reports_when_no_cli_integrations_are_installed(): void
    {
        $service = $this->makeService(active: [], runnable: []);

        $docs = $service->listDocs();

        $this->assertStringContainsString('No active Lua integration namespaces are available in this session.', $docs);
        $this->assertStringContainsString('No installed CLI-compatible integrations were found.', $docs);
        $this->assertStringNotContainsString('Examples: app.integrations.', $docs);
    }

    public function test_prompt_namespace_summary_only_lists_active_integrations(): void
    {
        $service = $this->makeService(
            active: ['plane'],
            runnable: ['plane', 'plausible', 'coingecko'],
        );

        $summary = $service->getPromptNamespaceSummary();

        $this->assertStringContainsString('app.integrations.*', $summary);
        $this->assertStringContainsString('app.integrations.plane', $summary);
        $this->assertStringNotContainsString('app.integrations.plausible', $summary);
        $this->assertStringNotContainsString('app.integrations.coingecko', $summary);
        $this->assertStringContainsString('app.tools.*', $summary);
    }

    public function test_namespace_summary_omits_integrations_block_when_nothing_is_installed(): void
    {
        $service = $this->makeService(active: [], runnable: []);

        $summary = $service->getNamespaceSummary();

        $this->assertStringNotContainsString('app.integrations.*', $summary);
        $this->assertStringContainsString('app.tools.*', $summary);
    }

    public function test_read_doc_for_inactive_namespace_explains_how_to_activate_it(): void
    {
        $service = $this->makeService(active: [], runnable: ['plane', 'plausible']);

        $docs = $service->readDoc('integrations.plane');

        $this->assertStringContainsString("Namespace 'integrations.plane' is installed but not active in this session.", $docs);
        $this->assertStringContainsString("Enable and configure 'plane' in /settings → Integrations", $docs);
        $this->assertStringContainsString('app.integrations.plane', $docs);
        $this->assertStringContainsString('app.integrations.plausible', $docs);
    }

    public function test_read_doc_for_inactive_namespace_function_explains_how_to_activate_it(): void
    {
        $service = $this->makeService(active: [], runnable: ['plane']);

        $docs = $service->readDoc('integrations.plane.list_projects');

        $this->assertStringContainsString("Namespace 'integrations.plane' is installed but not active in this session.", $docs);
        $this->assertStringContainsString("Enable and configure 'plane' in /settings → Integrations", $docs);
    }

    public function test_list_docs_appends_native_tools_when_bridge_is_present(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->fakeNativeTool(
            name: 'file_read',
            description: 'Read a file from disk',
            parameters: ['path' => 'Absolute or relative path'],
        ));

        $service = $this->makeService(
            active: [],
            runnable: [],
            nativeToolBridge: new NativeToolBridge(fn () => $registry),
        );

        $docs = $service->listDocs();

        $this->assertStringContainsString('**Native tools** (app.tools.*): `file_read`', $docs);
        $this->assertStringContainsString('Use `lua_read_doc page: tools` for details.', $docs);
    }

    public function test_list_docs_hides_redundant_default_namespace_aliases(): void
    {
        $service = $this->makeService(
            active: ['coingecko'],
            runnable: ['coingecko'],
            toolCatalog: [[
                'name' => 'coingecko',
                'description' => 'Cryptocurrency market data',
                'isIntegration' => true,
                'accounts' => ['work'],
                'tools' => [
                    [
                        'slug' => 'coingecko_search',
                        'name' => 'Search',
                        'description' => 'Search coins',
                    ],
                ],
            ]],
        );

        $docs = $service->listDocs();

        $this->assertStringContainsString('**app.integrations.coingecko** — Cryptocurrency market data', $docs);
        $this->assertStringContainsString('**app.integrations.coingecko.work** — Cryptocurrency market data', $docs);
        $this->assertStringNotContainsString('**app.integrations.coingecko.default**', $docs);
        $this->assertStringContainsString('Use `lua_read_doc` to inspect a namespace before calling its functions.', $docs);
    }

    public function test_list_docs_with_root_namespace_filter_hides_default_aliases(): void
    {
        $service = $this->makeService(
            active: ['coingecko'],
            runnable: ['coingecko'],
            toolCatalog: [[
                'name' => 'coingecko',
                'description' => 'Cryptocurrency market data',
                'isIntegration' => true,
                'accounts' => ['work'],
                'tools' => [
                    [
                        'slug' => 'coingecko_search',
                        'name' => 'Search',
                        'description' => 'Search coins',
                    ],
                ],
            ]],
        );

        $docs = $service->listDocs('integrations.coingecko');

        $this->assertStringContainsString('**app.integrations.coingecko** — Cryptocurrency market data', $docs);
        $this->assertStringContainsString('**app.integrations.coingecko.work** — Cryptocurrency market data', $docs);
        $this->assertStringNotContainsString('**app.integrations.coingecko.default**', $docs);
    }

    public function test_list_docs_with_default_namespace_filter_keeps_default_alias_visible(): void
    {
        $service = $this->makeService(
            active: ['coingecko'],
            runnable: ['coingecko'],
            toolCatalog: [[
                'name' => 'coingecko',
                'description' => 'Cryptocurrency market data',
                'isIntegration' => true,
                'tools' => [
                    [
                        'slug' => 'coingecko_search',
                        'name' => 'Search',
                        'description' => 'Search coins',
                    ],
                ],
            ]],
        );

        $docs = $service->listDocs('integrations.coingecko.default');

        $this->assertStringContainsString('**app.integrations.coingecko.default** — Cryptocurrency market data', $docs);
    }

    /**
     * @param  list<string>  $active
     * @param  list<string>  $runnable
     */
    private function makeService(
        array $active,
        array $runnable,
        ?NativeToolBridge $nativeToolBridge = null,
        array $toolCatalog = [],
    ): LuaDocService {
        $manager = $this->createStub(IntegrationManager::class);
        $manager->method('getActiveProviders')->willReturn($this->fakeProviderMap($active));
        $manager->method('getLocallyRunnableProviders')->willReturn($this->fakeProviderMap($runnable));
        $manager->method('getToolCatalog')->willReturn($toolCatalog);

        return new LuaDocService(
            new ToolProviderRegistry,
            $manager,
            new LuaCatalogBuilder,
            new LuaDocRenderer,
            $nativeToolBridge,
        );
    }

    /**
     * @param  list<string>  $names
     * @return array<string, ToolProvider>
     */
    private function fakeProviderMap(array $names): array
    {
        $providers = [];

        foreach ($names as $name) {
            $providers[$name] = $this->fakeProvider($name);
        }

        return $providers;
    }

    private function fakeProvider(string $name): ToolProvider
    {
        return new class($name) implements ToolProvider
        {
            public function __construct(private readonly string $name) {}

            public function appName(): string
            {
                return $this->name;
            }

            public function appMeta(): array
            {
                return [
                    'label' => ucfirst($this->name),
                    'description' => ucfirst($this->name).' integration',
                    'icon' => 'ph:puzzle-piece',
                ];
            }

            public function tools(): array
            {
                return [];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                throw new \RuntimeException('not used');
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [];
            }
        };
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function fakeNativeTool(string $name, string $description, array $parameters = []): ToolInterface
    {
        return new class($name, $description, $parameters) implements ToolInterface
        {
            /**
             * @param  array<string, string>  $parameters
             */
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly array $parameters,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return $this->description;
            }

            public function parameters(): array
            {
                $schema = [];
                foreach ($this->parameters as $name => $description) {
                    $schema[$name] = [
                        'type' => 'string',
                        'description' => $description,
                    ];
                }

                return $schema;
            }

            public function requiredParameters(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return ToolResult::success(json_encode($args) ?: '{}');
            }
        };
    }
}
