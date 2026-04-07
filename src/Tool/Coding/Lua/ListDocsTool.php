<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Lua;

use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

class ListDocsTool extends AbstractTool
{
    public function __construct(
        private readonly LuaDocService $docs,
    ) {}

    public function name(): string
    {
        return 'lua_list_docs';
    }

    public function description(): string
    {
        return 'List available Lua API namespaces and functions. Each namespace maps to an integration (plausible, coingecko, celestial, etc.). Shows function signatures with parameter names. Use this first to discover what integrations are available.';
    }

    public function parameters(): array
    {
        return [
            'namespace' => ['type' => 'string', 'description' => 'Filter to a specific namespace (e.g. "integrations.plausible"). Omit to list all.'],
        ];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    protected function handle(array $args): ToolResult
    {
        $namespace = $args['namespace'] ?? null;
        $result = $this->docs->listDocs($namespace);

        if (trim($result) === '') {
            return ToolResult::success('No Lua integration namespaces available. Configure integrations via /settings → Integrations.');
        }

        return ToolResult::success($result);
    }
}
