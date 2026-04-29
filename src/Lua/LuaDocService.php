<?php

declare(strict_types=1);

namespace Kosmokrator\Lua;

use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Lua\LuaDocRenderer;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class LuaDocService
{
    /** @var array<string, array{description: string, functions: array}>|null */
    private ?array $cachedNamespaces = null;

    public function __construct(
        private readonly ToolProviderRegistry $providers,
        private readonly IntegrationManager $integrationManager,
        private readonly LuaCatalogBuilder $catalogBuilder,
        private readonly LuaDocRenderer $docRenderer,
        private readonly ?NativeToolBridge $nativeToolBridge = null,
        private readonly ?McpCatalog $mcpCatalog = null,
        private readonly ?McpPermissionEvaluator $mcpPermissions = null,
    ) {}

    /**
     * List all available namespaces with function signatures.
     */
    public function listDocs(?string $namespace = null): string
    {
        $namespaces = $namespace !== null && str_ends_with($namespace, '.default')
            ? $this->buildNamespaces()
            : $this->buildVisibleNamespaces();

        if ($namespaces === [] && $namespace === null) {
            $availableProviders = array_keys($this->integrationManager->getLocallyRunnableProviders());
            sort($availableProviders);

            $output = "No active Lua integration namespaces are available in this session.\n\n"
                .$this->summarizeInactiveIntegrations($availableProviders);
        } else {
            $output = $this->docRenderer->generateNamespaceIndex(
                $namespaces,
                $this->getStaticPageContents(),
                $namespace,
            );
        }

        // Append native tools section
        if ($this->nativeToolBridge !== null) {
            $tools = $this->nativeToolBridge->listTools();
            if ($tools !== []) {
                $toolList = implode(', ', array_map(
                    fn (string $name) => "`{$name}`",
                    array_keys($tools),
                ));
                $output .= "\n\n**Native tools** (app.tools.*): {$toolList}\nUse `lua_read_doc page: tools` for details.";
            }
        }

        return $output;
    }

    /**
     * Search docs by keyword across all namespaces, native tools, and static pages.
     */
    public function searchDocs(string $query, int $limit = 10): string
    {
        $namespaces = $this->buildVisibleNamespaces();

        // Include native tools as a virtual namespace so they appear in search results
        if ($this->nativeToolBridge !== null) {
            $namespaces['tools'] = $this->buildNativeToolsNamespace();
        }

        return $this->docRenderer->search(
            $query,
            $namespaces,
            $this->getStaticPageContents(),
            $limit,
        );
    }

    /**
     * Read detailed docs for a namespace, function, or static guide page.
     */
    public function readDoc(string $page): string
    {
        // Strip "app." prefix — docs display functions as app.namespace.fn()
        // but internal namespace keys don't include the "app." prefix
        if (str_starts_with($page, 'app.')) {
            $page = substr($page, 4);
        }

        // Native tools namespace
        if ($page === 'tools') {
            return $this->readNativeToolsDocs();
        }

        // Try static page first
        $static = $this->readStaticPage($page);
        if ($static !== null) {
            return $static;
        }

        // Try namespace.function format
        if (str_contains($page, '.')) {
            $parts = explode('.', $page, 2);
            $ns = $parts[0];
            $function = $parts[1];

            // Handle multi-level: "integrations.gmail.send_email"
            if (in_array($ns, ['integrations', 'mcp']) && str_contains($function, '.')) {
                $subParts = explode('.', $function, 2);
                $ns = $ns.'.'.$subParts[0];
                $function = $subParts[1];
            }
            // Handle namespace-only: "integrations.gmail"
            elseif (in_array($ns, ['integrations', 'mcp'])) {
                $inactive = $this->inactiveNamespaceMessage($ns.'.'.$function);
                if ($inactive !== null) {
                    return $inactive;
                }

                return $this->docRenderer->generateNamespaceDocs(
                    $ns.'.'.$function,
                    $this->buildNamespaces(),
                    fn (string $n) => $this->getProviderLuaDocs($n),
                );
            }

            $inactive = $this->inactiveNamespaceMessage($ns);
            if ($inactive !== null) {
                return $inactive;
            }

            return $this->docRenderer->generateFunctionDocs(
                $ns,
                $function,
                $this->buildNamespaces(),
            );
        }

        // Try as namespace
        $inactive = $this->inactiveNamespaceMessage($page);
        if ($inactive !== null) {
            return $inactive;
        }

        $namespaceDocs = $this->docRenderer->generateNamespaceDocs(
            $page,
            $this->buildNamespaces(),
            fn (string $n) => $this->getProviderLuaDocs($n),
        );

        if (! str_starts_with($namespaceDocs, "Namespace '{$page}' not found.")) {
            return $namespaceDocs;
        }

        // Not found — show available pages
        $available = $this->docRenderer->getAvailablePages(
            $this->buildNamespaces(),
            $this->getStaticPageContents(),
        );

        $namespaces = array_filter($available, fn (string $p) => ! in_array($p, ['overview', 'context', 'errors', 'examples']));
        $guides = ['overview', 'context', 'errors', 'examples'];

        return "Page '{$page}' not found. Available pages:\n\n"
            .'**Namespaces:** '.implode(', ', $namespaces)."\n"
            .'**Guides:** '.implode(', ', $guides);
    }

    /**
     * Build the function map for LuaBridge routing.
     *
     * @return array<string, string>
     */
    public function buildFunctionMap(): array
    {
        return $this->catalogBuilder->buildFunctionMap($this->buildNamespaces());
    }

    /**
     * Build the parameter map for positional arg mapping.
     *
     * @return array<string, list<string>>
     */
    public function buildParameterMap(): array
    {
        return $this->catalogBuilder->buildParameterMap($this->buildNamespaces());
    }

    /**
     * Build the account map for multi-account routing.
     *
     * @return array<string, string>
     */
    public function buildAccountMap(): array
    {
        return $this->catalogBuilder->buildAccountMap($this->buildNamespaces());
    }

    /**
     * Get a short summary of available namespaces for system prompt injection.
     */
    public function getNamespaceSummary(): string
    {
        $parts = [];
        $activeProviders = array_keys($this->integrationManager->getActiveProviders());
        $availableProviders = array_keys($this->integrationManager->getLocallyRunnableProviders());
        sort($activeProviders);
        sort($availableProviders);

        if ($availableProviders !== []) {
            $inactiveProviders = array_values(array_diff($availableProviders, $activeProviders));
            $lines = [
                'app.integrations.* — Installed integration namespaces exposed to Lua.',
                '  Active now:',
                '    '.$this->summarizeNamespaces($activeProviders),
            ];

            if ($inactiveProviders !== []) {
                $lines[] = '  Available but inactive:';
                $lines[] = '    '.$this->summarizeNamespaces($inactiveProviders);
            }

            $lines[] = '  Multi-credential namespaces: configured integrations can also expose `app.integrations.{name}.default` and `app.integrations.{name}.{account}` aliases.';
            $lines[] = '  Use lua_list_docs or lua_read_doc to inspect a namespace before writing Lua code.';
            $lines[] = '  If an integration is inactive, enable/configure it in /settings → Integrations.';
            $parts[] = implode("\n", $lines);
        }

        $mcpServers = $this->mcpCatalog?->servers(false) ?? [];
        if ($mcpServers !== []) {
            $names = array_map(static fn (string $name): string => "app.mcp.{$name}", array_keys($mcpServers));
            $parts[] = "app.mcp.* — Configured MCP server namespaces exposed to Lua.\n  Active now:\n    ".implode(', ', $names)."\n  Use lua_read_doc page=\"mcp.SERVER\" before calling MCP tools.";
        }

        $parts[] = "app.tools.* — Native KosmoKrator tools (file_read, glob, grep, bash, subagent, etc.). See lua_read_doc page 'overview' for details.";

        return implode("\n\n", $parts);
    }

    public function getPromptNamespaceSummary(): string
    {
        $activeProviders = array_keys($this->integrationManager->getActiveProviders());
        sort($activeProviders);

        $parts = [];

        if ($activeProviders !== []) {
            $lines = [
                'app.integrations.* — Active integration namespaces exposed to Lua in this session.',
                '  Active now:',
                '    '.$this->summarizeNamespaces($activeProviders),
                '  Multi-credential namespaces can also expose `app.integrations.{name}.default` and `app.integrations.{name}.{account}` aliases.',
                '  Use lua_list_docs or lua_read_doc to inspect a namespace before writing Lua code.',
            ];
            $parts[] = implode("\n", $lines);
        }

        $mcpServers = $this->mcpCatalog?->servers(false) ?? [];
        if ($mcpServers !== []) {
            $names = array_map(static fn (string $name): string => "app.mcp.{$name}", array_keys($mcpServers));
            $parts[] = "app.mcp.* — Active MCP server namespaces exposed to Lua in this session.\n  Active now:\n    ".implode(', ', $names)."\n  Use lua_read_doc page=\"mcp.SERVER\" before calling MCP tools.";
        }

        $parts[] = "app.tools.* — Native KosmoKrator tools (file_read, glob, grep, bash, subagent, etc.). See lua_read_doc page 'overview' for details.";

        return implode("\n\n", $parts);
    }

    /**
     * Get the list of available page slugs.
     *
     * @return list<string>
     */
    public function getAvailablePages(): array
    {
        $pages = $this->docRenderer->getAvailablePages(
            $this->buildVisibleNamespaces(),
            $this->getStaticPageContents(),
        );

        // Always include the native tools namespace
        if (! in_array('tools', $pages)) {
            $pages[] = 'tools';
        }

        return $pages;
    }

    /**
     * @param  list<string>  $providers
     */
    private function summarizeNamespaces(array $providers): string
    {
        if ($providers === []) {
            return 'none';
        }

        $namespaces = array_map(
            static fn (string $name): string => "app.integrations.{$name}",
            $providers,
        );

        $chunks = array_chunk($namespaces, 12);
        $lines = array_map(
            static fn (array $chunk): string => implode(', ', $chunk),
            $chunks,
        );

        return implode("\n    ", $lines);
    }

    private function inactiveNamespaceMessage(string $page): ?string
    {
        if (! str_starts_with($page, 'integrations.')) {
            return null;
        }

        $parts = explode('.', $page);
        $provider = $parts[1] ?? '';
        if ($provider === '') {
            return null;
        }

        if (array_key_exists($provider, $this->integrationManager->getActiveProviders())) {
            return null;
        }

        if (! array_key_exists($provider, $this->integrationManager->getLocallyRunnableProviders())) {
            return null;
        }

        return "Namespace '{$page}' is installed but not active in this session.\n\n"
            ."Enable and configure '{$provider}' in /settings → Integrations, then start a new turn or session.\n\n"
            .$this->getNamespaceSummary();
    }

    /**
     * @param  list<string>  $providers
     */
    private function summarizeInactiveIntegrations(array $providers): string
    {
        if ($providers === []) {
            return 'No installed CLI-compatible integrations were found. Configure integrations via /settings → Integrations.';
        }

        $examples = array_slice($providers, 0, 12);
        $line = implode(', ', array_map(
            static fn (string $name): string => "app.integrations.{$name}",
            $examples,
        ));

        $suffix = count($providers) > count($examples)
            ? "\nExamples: {$line}, ... +".(count($providers) - count($examples)).' more'
            : "\nExamples: {$line}";

        return 'Installed but inactive integrations: '.count($providers).".\n"
            .'Enable/configure one in /settings → Integrations to expose its Lua namespace.'
            .$suffix
            ."\nUse lua_read_doc(page: \"integrations.NAME\") after enabling one.";
    }

    /**
     * @return array<string, array{description: string, functions: array}>
     */
    private function buildNamespaces(): array
    {
        if ($this->cachedNamespaces !== null) {
            return $this->cachedNamespaces;
        }

        $catalog = $this->integrationManager->getToolCatalog();

        $this->cachedNamespaces = $this->catalogBuilder->buildNamespaces(
            $catalog,
            ['tasks', 'system', 'lua'],
        );

        if ($this->mcpCatalog !== null) {
            $this->cachedNamespaces = array_merge(
                $this->cachedNamespaces,
                $this->mcpCatalog->luaNamespaces(
                    fn ($config): bool => $this->mcpPermissions?->isTrusted($config) ?? true,
                ),
            );
            ksort($this->cachedNamespaces, SORT_STRING);
        }

        return $this->cachedNamespaces;
    }

    /**
     * Collapse redundant `.default` aliases from discovery surfaces while
     * keeping them callable for direct docs lookups and runtime execution.
     *
     * @return array<string, array{description: string, functions: array}>
     */
    private function buildVisibleNamespaces(): array
    {
        $namespaces = $this->buildNamespaces();

        foreach (array_keys($namespaces) as $namespace) {
            if (! str_ends_with($namespace, '.default')) {
                continue;
            }

            $base = substr($namespace, 0, -strlen('.default'));
            if ($base !== '' && array_key_exists($base, $namespaces)) {
                unset($namespaces[$namespace]);
            }
        }

        return $namespaces;
    }

    /**
     * Build a virtual namespace entry for native tools, matching the format
     * expected by LuaDocRenderer::search() and other methods.
     *
     * @return array{description: string, functions: array<int, array{name: string, description: string, fullDescription: string, parameters: array<int, array<string, mixed>>, sourceToolSlug: string}>}
     */
    private function buildNativeToolsNamespace(): array
    {
        $functions = [];

        foreach ($this->nativeToolBridge->listTools() as $name => $meta) {
            $parameters = [];
            foreach ($meta['parameters'] as $paramName => $paramDesc) {
                $parameters[] = [
                    'name' => $paramName,
                    'type' => 'string',
                    'required' => false,
                    'description' => $paramDesc,
                ];
            }

            $functions[] = [
                'name' => $name,
                'description' => $meta['description'],
                'fullDescription' => $meta['description'],
                'parameters' => $parameters,
                'sourceToolSlug' => $name,
            ];
        }

        return [
            'description' => 'Native KosmoKrator tools',
            'functions' => $functions,
        ];
    }

    /**
     * Get supplementary Lua docs from a ToolProvider.
     */
    private function getProviderLuaDocs(string $namespace): ?string
    {
        $appName = str_starts_with($namespace, 'integrations.')
            ? substr($namespace, strlen('integrations.'))
            : $namespace;

        // Strip account segment for multi-account namespaces
        if ($this->providers->get($appName) === null && str_contains($appName, '.')) {
            $appName = explode('.', $appName, 2)[0];
        }

        $provider = $this->providers->get($appName);

        if ($provider === null) {
            return null;
        }

        $path = $provider->luaDocsPath();
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content !== false ? $content : null;
    }

    /**
     * @return array<string, string> slug => file path
     */
    private function getStaticPages(): array
    {
        $dir = dirname(__DIR__, 2).'/resources/lua-docs';

        if (! is_dir($dir)) {
            return [];
        }

        $pages = [];
        foreach (glob($dir.'/*.md') ?: [] as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $pages[ltrim($slug, '_')] = $file;
        }

        return $pages;
    }

    /**
     * @return array<string, string> slug => content
     */
    private function getStaticPageContents(): array
    {
        $contents = [];
        foreach ($this->getStaticPages() as $slug => $path) {
            $content = file_get_contents($path);
            if ($content !== false) {
                $contents[$slug] = $content;
            }
        }

        return $contents;
    }

    private function readStaticPage(string $slug): ?string
    {
        $pages = $this->getStaticPages();

        if (! isset($pages[$slug])) {
            return null;
        }

        $content = file_get_contents($pages[$slug]);

        return $content !== false ? $content : null;
    }

    /**
     * Generate documentation for the app.tools native namespace.
     */
    private function readNativeToolsDocs(): string
    {
        $lines = ['# Native KosmoKrator Tools (app.tools.*)'];
        $lines[] = '';
        $lines[] = 'KosmoKrator\'s built-in tools are available in the `app.tools` namespace.';
        $lines[] = 'Call them with a table of parameters: `app.tools.file_read({path = "..."})`';
        $lines[] = '';
        $lines[] = '## Available Tools';
        $lines[] = '';

        if ($this->nativeToolBridge !== null) {
            foreach ($this->nativeToolBridge->listTools() as $name => $meta) {
                $lines[] = "### `{$name}`";
                $lines[] = $meta['description'];
                if ($meta['parameters'] !== []) {
                    $lines[] = '';
                    $lines[] = '**Parameters:**';
                    foreach ($meta['parameters'] as $param => $desc) {
                        $lines[] = "- `{$param}` — {$desc}";
                    }
                }
                $lines[] = '';
            }
        } else {
            $lines[] = 'No native tools available.';
        }

        $lines[] = '## Examples';
        $lines[] = '';
        $lines[] = '```lua';
        $lines[] = '-- Read a file';
        $lines[] = 'local content = app.tools.file_read({path = "src/Kernel.php"})';
        $lines[] = 'print(content)';
        $lines[] = '';
        $lines[] = '-- Search code';
        $lines[] = 'local matches = app.tools.grep({pattern = "function boot", path = "src/"})';
        $lines[] = 'print(matches)';
        $lines[] = '';
        $lines[] = '-- List files';
        $lines[] = 'local files = app.tools.glob({pattern = "src/**/*.php"})';
        $lines[] = 'print(files)';
        $lines[] = '';
        $lines[] = '-- Run a command';
        $lines[] = 'local output = app.tools.bash({command = "git status --short"})';
        $lines[] = 'print(output)';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Subagent';
        $lines[] = '';
        $lines[] = 'The `subagent` tool supports two calling conventions:';
        $lines[] = '- **Single:** pass `task` (string) — spawns one agent, blocks until done.';
        $lines[] = '- **Batch:** pass `agents` (array of specs) — spawns all concurrently, blocks until all done.';
        $lines[] = '';
        $lines[] = '### Single Agent';
        $lines[] = '';
        $lines[] = '```lua';
        $lines[] = 'local result = app.tools.subagent({';
        $lines[] = '  task = "Find all files using the AgentContext class",';
        $lines[] = '  type = "explore",';
        $lines[] = '})';
        $lines[] = 'print(result)';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '### Batch — Parallel Agents';
        $lines[] = '';
        $lines[] = '```lua';
        $lines[] = 'local result = app.tools.subagent({';
        $lines[] = '  agents = {';
        $lines[] = '    {task = "Explore routing", id = "r1"},';
        $lines[] = '    {task = "Explore auth",    id = "r2"},';
        $lines[] = '    {task = "Explore DB",      id = "r3"},';
        $lines[] = '  }';
        $lines[] = '})';
        $lines[] = 'print(result)  -- all results keyed by id';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '### mode: await vs background';
        $lines[] = '';
        $lines[] = '`mode` applies to both single and batch. Default is "await".';
        $lines[] = '- `"await"`: blocks until agent(s) complete. Results returned directly.';
        $lines[] = '- `"background"`: returns immediately. Results collected by main agent loop after Lua returns.';
        $lines[] = '';
        $lines[] = '### Per-agent options in batch';
        $lines[] = '';
        $lines[] = 'Each spec in `agents` supports:';
        $lines[] = '- `task` (required) — what the agent should do';
        $lines[] = '- `type` — "explore" (default), "plan", or "general"';
        $lines[] = '- `id` — name for depends_on references';
        $lines[] = '- `depends_on` — array of agent IDs that must finish first (results injected into task)';
        $lines[] = '- `group` — agents with the same group run sequentially; different groups run concurrently';
        $lines[] = '';
        $lines[] = 'See the overview page for detailed examples of dependencies, groups, and background mode.';

        return implode("\n", $lines);
    }
}
