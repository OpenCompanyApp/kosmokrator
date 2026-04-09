<?php

declare(strict_types=1);

namespace Kosmokrator\Lua;

use Kosmokrator\Tool\ToolRegistry;
use Lua\Sandbox;

/**
 * Bridges KosmoKrator's native tools (file_read, glob, grep, bash, etc.)
 * into the Lua `app.tools.*` namespace.
 *
 * Uses a lazy closure to avoid circular dependency with ToolRegistry.
 */
class NativeToolBridge
{
    /** @var \Closure(): ToolRegistry */
    private \Closure $registryResolver;

    /** @var array<string, array<string, string>> Tool name → {description, parameters} cache */
    private ?array $toolMetaCache = null;

    /**
     * @param  \Closure(): ToolRegistry  $registryResolver  Lazy resolver to avoid circular deps
     */
    public function __construct(
        \Closure $registryResolver,
    ) {
        $this->registryResolver = $registryResolver;
    }

    /**
     * Execute a native tool by name and return a structured result for Lua.
     *
     * Returns a PHP array that becomes a Lua table:
     *   {output = "raw text", success = true, stdout = "...", stderr = "...", exit_code = 0}
     *
     * stdout/stderr/exit_code are only present when the tool provides metadata (e.g. bash).
     */
    public function call(string $toolName, array $args): mixed
    {
        $registry = ($this->registryResolver)();
        $tool = $registry->get($toolName);

        if ($tool === null) {
            throw new \RuntimeException("Unknown native tool: {$toolName}. Use lua_list_docs to discover available tools.");
        }

        // Skip file_read cache when called from Lua — Lua scripts need fresh data
        if ($toolName === 'file_read' && ! isset($args['fresh'])) {
            $args['fresh'] = true;
        }

        $result = $tool->execute($args);

        if (! $result->success) {
            throw new \RuntimeException($result->output);
        }

        $table = [
            'output' => $result->output,
            'success' => $result->success,
        ];

        if ($result->metadata !== null) {
            foreach ($result->metadata as $key => $value) {
                $table[$key] = $value;
            }
        }

        // Debug: log the return type to verify PHP side is correct

        return $table;
    }

    /**
     * List available native tools for the Lua docs system.
     *
     * @return array<string, array{description: string, parameters: array<string, string>}>
     */
    public function listTools(): array
    {
        if ($this->toolMetaCache !== null) {
            return $this->toolMetaCache;
        }

        $registry = ($this->registryResolver)();
        $this->toolMetaCache = [];

        foreach ($registry->all() as $tool) {
            $name = $tool->name();
            // Exclude Lua tools themselves (avoid recursion) and interactive prompt tools
            if (in_array($name, ['execute_lua', 'lua_list_docs', 'lua_search_docs', 'lua_read_doc', 'ask_user', 'ask_choice'], true)) {
                continue;
            }

            $params = [];
            foreach ($tool->parameters() as $key => $schema) {
                $params[$key] = $schema['description'] ?? '';
            }

            $this->toolMetaCache[$name] = [
                'description' => $tool->description(),
                'parameters' => $params,
            ];
        }

        return $this->toolMetaCache;
    }

    /**
     * Register native tools into the Lua sandbox's app.tools.* namespace.
     *
     * Must be called AFTER setupAppNamespace() so we can patch the app namespace
     * to recognize 'tools' as a special sub-namespace.
     */
    public function register(Sandbox $sandbox): void
    {
        $bridge = $this;

        $sandbox->register('__native', [
            'call' => function (string $toolName, mixed ...$args) use ($bridge) {
                // Lua passes tables; convert to associative array
                $params = [];
                if (isset($args[0]) && is_array($args[0])) {
                    $params = $args[0];
                } elseif (isset($args[0])) {
                    $params = ['input' => $args[0]];
                }

                try {
                    $result = $bridge->call($toolName, $params);

                    return $result;
                } catch (\Throwable $e) {
                    return ['__error' => $e->getMessage()];
                }
            },
        ]);

        // Build tool names for the Lua-side namespace
        $tools = $this->listTools();
        $toolNames = array_keys($tools);
        $toolNamesLua = '{'.implode(', ', array_map(fn (string $n) => '"'.addcslashes($n, '"\\').'"', $toolNames)).'}';

        // Inject app.tools as a special sub-namespace.
        // If app exists (from integration bridge), patch into it.
        // If not, create app as a plain table with just tools.
        $sandbox->load("
            local __tool_names = {$toolNamesLua}
            local function make_native_tool(name)
                return function(...)
                    local result = __native.call(name, ...)
                    if type(result) == 'table' and result.__error then
                        -- Return error as string, don't throw — same behavior as regular tool calls
                        return result.__error
                    end
                    return result
                end
            end
            local __tools_mt = {
                __index = function(self, key)
                    for _, name in ipairs(__tool_names) do
                        if name == key then
                            local fn = make_native_tool(name)
                            rawset(self, key, fn)
                            return fn
                        end
                    end
                    return nil
                end
            }
            -- Create or patch into app namespace
            if app == nil then
                app = {}
            end
            rawset(app, 'tools', setmetatable({}, __tools_mt))
        ")->call();
    }
}
