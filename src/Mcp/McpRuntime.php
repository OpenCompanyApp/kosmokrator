<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

use Kosmokrator\Integration\Runtime\LuaExecutionResult;
use Kosmokrator\Lua\LuaSandboxService;
use OpenCompany\IntegrationCore\Lua\LuaBridge;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;

final class McpRuntime
{
    public function __construct(
        private readonly McpConfigStore $configs,
        private readonly McpCatalog $catalog,
        private readonly McpClientManager $clients,
        private readonly McpPermissionEvaluator $permissions,
        private readonly McpLuaToolInvoker $invoker,
        private readonly LuaSandboxService $lua,
        private readonly LuaCatalogBuilder $catalogBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $name, array $args, bool $force = false, bool $dryRun = false): array
    {
        $start = microtime(true);

        try {
            $tool = $this->catalog->find($name);
            if ($tool === null) {
                throw new \RuntimeException("Unknown MCP function: {$name}");
            }

            $server = $this->configs->get($tool->server);
            if ($server === null) {
                throw new \RuntimeException("Unknown MCP server: {$tool->server}");
            }

            $this->permissions->assertAllowed($server, $tool, $force);

            if ($dryRun) {
                return $this->result($name, null, true, null, $start, [
                    'dry_run' => true,
                    'operation' => $tool->operation(),
                    'permission_bypassed' => $force,
                    'server' => $server->name,
                ]);
            }

            $data = self::normalizeToolResult($this->clients->client($tool->server)->callTool($tool->name, $args));

            return $this->result($name, $data, true, null, $start, [
                'dry_run' => false,
                'operation' => $tool->operation(),
                'permission_bypassed' => $force,
                'server' => $server->name,
            ]);
        } catch (\Throwable $e) {
            return $this->result($name, null, false, $e->getMessage(), $start, [
                'dry_run' => $dryRun,
                'permission_bypassed' => $force,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function executeLua(string $code, array $options = []): LuaExecutionResult
    {
        $force = (bool) ($options['force'] ?? false);
        unset($options['force']);

        $namespaces = $this->luaNamespaces($force);
        $bridge = $namespaces === []
            ? null
            : new LuaBridge(
                $this->catalogBuilder->buildFunctionMap($namespaces),
                $this->catalogBuilder->buildParameterMap($namespaces),
                $this->invoker,
            );

        $phpFunctions = $this->helperFunctions($force);
        $result = $this->invoker->runWithForce(
            $force,
            fn () => $this->lua->execute($code, $options, $bridge, phpFunctions: $phpFunctions),
        );

        return new LuaExecutionResult(
            lua: $result,
            callLog: $bridge?->getCallLog() ?? [],
            meta: ['permission_bypassed' => $force],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeToolResult(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result;
        }

        if (($result['isError'] ?? false) === true) {
            throw new \RuntimeException(self::contentToString($result['content'] ?? []) ?: 'MCP tool returned an error.');
        }

        $content = $result['content'] ?? null;
        if (! is_array($content)) {
            return $result;
        }

        if (count($content) === 1 && is_array($content[0] ?? null) && ($content[0]['type'] ?? null) === 'text') {
            $text = (string) ($content[0]['text'] ?? '');
            $decoded = json_decode($text, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
        }

        return $content;
    }

    public static function contentToString(mixed $content): string
    {
        if (! is_array($content)) {
            return is_scalar($content) ? (string) $content : '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text') {
                $parts[] = (string) ($item['text'] ?? '');
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<string, callable>
     */
    public function helperFunctions(bool $force = false): array
    {
        return [
            'mcp.servers' => fn () => array_map(fn (McpServerConfig $server): array => $this->serverRow($server), $this->configs->effectiveServers()),
            'mcp.tools' => fn (?string $server = null) => array_map(
                fn (McpToolDefinition $tool): array => $this->toolRow($tool),
                $this->catalog->tools($server, fn (McpServerConfig $config): bool => $this->permissions->isTrusted($config, $force)),
            ),
            'mcp.schema' => function (string $name) use ($force): array {
                [$serverName] = array_pad(explode('.', preg_replace('/^mcp\./', '', $name) ?? $name, 2), 2, '');
                $server = $this->configs->get($serverName);
                if ($server !== null) {
                    $this->permissions->assertTrusted($server, $force);
                }

                $tool = $this->catalog->find($name);
                if ($tool === null) {
                    throw new \RuntimeException("Unknown MCP function: {$name}");
                }

                return $this->toolRow($tool);
            },
            'mcp.call' => fn (string $name, array $args = []) => $this->call($name, $args, $force)['data'] ?? null,
            'mcp.resources' => function (string $server) use ($force): array {
                $config = $this->configs->get($server);
                if ($config === null) {
                    throw new \RuntimeException("Unknown MCP server: {$server}");
                }
                $this->permissions->assertReadAllowed($config, $force);

                return $this->clients->client($server)->listResources();
            },
            'mcp.read_resource' => function (string $server, string $uri) use ($force): mixed {
                $config = $this->configs->get($server);
                if ($config === null) {
                    throw new \RuntimeException("Unknown MCP server: {$server}");
                }
                $this->permissions->assertReadAllowed($config, $force);

                return $this->clients->client($server)->readResource($uri);
            },
            'mcp.prompts' => function (string $server) use ($force): array {
                $config = $this->configs->get($server);
                if ($config === null) {
                    throw new \RuntimeException("Unknown MCP server: {$server}");
                }
                $this->permissions->assertReadAllowed($config, $force);

                return $this->clients->client($server)->listPrompts();
            },
            'mcp.get_prompt' => function (string $server, string $name, array $args = []) use ($force): mixed {
                $config = $this->configs->get($server);
                if ($config === null) {
                    throw new \RuntimeException("Unknown MCP server: {$server}");
                }
                $this->permissions->assertReadAllowed($config, $force);

                return $this->clients->client($server)->getPrompt($name, $args);
            },
        ];
    }

    /**
     * @return array<string, array{description: string, functions: list<array<string, mixed>>}>
     */
    public function luaNamespaces(bool $force = false): array
    {
        return $this->catalog->luaNamespaces(
            fn (McpServerConfig $config): bool => $this->permissions->isTrusted($config, $force),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serverRow(McpServerConfig $server): array
    {
        return [
            'name' => $server->name,
            'type' => $server->type,
            'enabled' => $server->enabled,
            'source' => $server->source,
            'path' => $server->path,
            'command' => $server->command,
            'args' => $server->args,
            'url' => $server->url,
            'timeout' => $server->timeoutSeconds,
            'permissions' => [
                'read' => $this->permissions->permission($server->name, 'read'),
                'write' => $this->permissions->permission($server->name, 'write'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolRow(McpToolDefinition $tool): array
    {
        return [
            'server' => $tool->server,
            'name' => $tool->name,
            'function' => "{$tool->server}.{$tool->luaName}",
            'lua_path' => "app.mcp.{$tool->server}.{$tool->luaName}",
            'description' => $tool->description,
            'operation' => $tool->operation(),
            'parameters' => $tool->parameters(),
            'schema' => $tool->inputSchema,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function result(string $function, mixed $data, bool $success, ?string $error, float $start, array $meta): array
    {
        return [
            'success' => $success,
            'function' => $function,
            'data' => $data,
            'error' => $error,
            'meta' => $meta,
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
        ];
    }
}
