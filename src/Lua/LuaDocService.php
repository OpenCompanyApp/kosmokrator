<?php

declare(strict_types=1);

namespace Kosmokrator\Lua;

use Kosmokrator\Integration\IntegrationManager;
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
    ) {}

    /**
     * List all available namespaces with function signatures.
     */
    public function listDocs(?string $namespace = null): string
    {
        $output = $this->docRenderer->generateNamespaceIndex(
            $this->buildNamespaces(),
            $this->getStaticPageContents(),
            $namespace,
        );

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
     * Search docs by keyword across all namespaces and static pages.
     */
    public function searchDocs(string $query, int $limit = 10): string
    {
        return $this->docRenderer->search(
            $query,
            $this->buildNamespaces(),
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
                return $this->docRenderer->generateNamespaceDocs(
                    $ns.'.'.$function,
                    $this->buildNamespaces(),
                    fn (string $n) => $this->getProviderLuaDocs($n),
                );
            }

            return $this->docRenderer->generateFunctionDocs(
                $ns,
                $function,
                $this->buildNamespaces(),
            );
        }

        // Try as namespace
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
        $namespaces = $this->buildNamespaces();

        $parts = [];

        // Integration namespaces
        if ($namespaces !== []) {
            $parts[] = $this->docRenderer->getNamespaceSummary($namespaces);
        }

        // Native tools namespace
        $parts[] = "app.tools.* — Native KosmoKrator tools (file_read, glob, grep, bash, etc.). See lua_read_doc page 'overview' for details.";

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
            $this->buildNamespaces(),
            $this->getStaticPageContents(),
        );

        // Always include the native tools namespace
        if (! in_array('tools', $pages)) {
            $pages[] = 'tools';
        }

        return $pages;
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

        return $this->cachedNamespaces;
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

        return implode("\n", $lines);
    }
}
