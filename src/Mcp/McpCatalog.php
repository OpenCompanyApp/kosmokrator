<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final class McpCatalog
{
    /** @var array<string, list<McpToolDefinition>> */
    private array $toolCache = [];

    public function __construct(
        private readonly McpConfigStore $configs,
        private readonly McpClientManager $clients,
    ) {}

    /**
     * @return array<string, McpServerConfig>
     */
    public function servers(bool $includeDisabled = true): array
    {
        return $this->configs->effectiveServers($includeDisabled);
    }

    /**
     * @return list<McpToolDefinition>
     */
    public function tools(?string $server = null, ?callable $serverAllowed = null): array
    {
        if ($server !== null) {
            $config = $this->configs->get($server);
            if ($config !== null && $serverAllowed !== null && ! $serverAllowed($config)) {
                return [];
            }

            return $this->toolsForServer($server);
        }

        $tools = [];
        foreach ($this->servers(false) as $name => $config) {
            if ($serverAllowed !== null && ! $serverAllowed($config)) {
                continue;
            }

            try {
                array_push($tools, ...$this->toolsForServer($name));
            } catch (\Throwable) {
                continue;
            }
        }

        usort($tools, static fn (McpToolDefinition $a, McpToolDefinition $b): int => strcmp($a->server.'.'.$a->luaName, $b->server.'.'.$b->luaName));

        return $tools;
    }

    public function find(string $name): ?McpToolDefinition
    {
        $name = preg_replace('/^mcp\./', '', $name) ?? $name;
        [$server, $tool] = array_pad(explode('.', $name, 2), 2, '');
        if ($server === '' || $tool === '') {
            return null;
        }

        foreach ($this->toolsForServer($server) as $definition) {
            if ($definition->name === $tool || $definition->luaName === $tool || "{$definition->server}.{$definition->luaName}" === $name) {
                return $definition;
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?McpToolDefinition
    {
        foreach ($this->tools() as $tool) {
            if ($tool->slug === $slug) {
                return $tool;
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->toolCache = [];
    }

    /**
     * @return array<string, array{description: string, functions: list<array<string, mixed>>}>
     */
    public function luaNamespaces(?callable $serverAllowed = null): array
    {
        $namespaces = [];

        foreach ($this->servers(false) as $server => $config) {
            if ($serverAllowed !== null && ! $serverAllowed($config)) {
                continue;
            }

            try {
                $functions = array_map(
                    static fn (McpToolDefinition $tool): array => [
                        'name' => $tool->luaName,
                        'description' => $tool->description,
                        'fullDescription' => $tool->description,
                        'parameters' => $tool->parameters(),
                        'sourceToolSlug' => $tool->slug,
                    ],
                    $this->toolsForServer($server),
                );
            } catch (\Throwable) {
                $functions = [];
            }

            if ($functions === []) {
                continue;
            }

            $namespaces["mcp.{$server}"] = [
                'description' => "MCP server {$server}",
                'functions' => $functions,
            ];
        }

        ksort($namespaces, SORT_STRING);

        return $namespaces;
    }

    /**
     * @return list<McpToolDefinition>
     */
    private function toolsForServer(string $server): array
    {
        if (isset($this->toolCache[$server])) {
            return $this->toolCache[$server];
        }

        $config = $this->configs->get($server);
        if ($config === null) {
            throw new \RuntimeException("Unknown MCP server: {$server}");
        }

        $tools = [];
        foreach ($this->clients->client($server)->listTools() as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $luaName = McpServerConfig::sanitizeIdentifier($name);
            $tools[] = new McpToolDefinition(
                server: $server,
                name: $name,
                luaName: $luaName,
                slug: $config->functionPrefix().$luaName,
                description: (string) ($tool['description'] ?? ''),
                inputSchema: is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object', 'properties' => []],
                annotations: is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
            );
        }

        return $this->toolCache[$server] = $tools;
    }
}
