<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Lua;

use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

class SearchDocsTool extends AbstractTool
{
    public function __construct(
        private readonly LuaDocService $docs,
    ) {}

    public function name(): string
    {
        return 'lua_search_docs';
    }

    public function description(): string
    {
        return 'Search the Lua scripting API documentation by keyword. Searches function names, descriptions, and parameter info across all available namespaces and supplementary docs.';
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'The search query (e.g. "send message", "analytics", "query stats").'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum number of results. Default: 10.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['query'];
    }

    protected function handle(array $args): ToolResult
    {
        $query = $args['query'] ?? '';

        if (trim($query) === '') {
            return ToolResult::error('Missing required parameter "query". Provide a search term.');
        }

        $limit = (int) ($args['limit'] ?? 10);
        $result = $this->docs->searchDocs($query, $limit);

        return ToolResult::success($result);
    }
}
