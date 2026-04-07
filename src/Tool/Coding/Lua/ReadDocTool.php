<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Lua;

use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

class ReadDocTool extends AbstractTool
{
    public function __construct(
        private readonly LuaDocService $docs,
    ) {}

    public function name(): string
    {
        return 'lua_read_doc';
    }

    public function description(): string
    {
        return <<<'DESC'
Read Lua API documentation for a namespace, function, or guide.

- Namespace (e.g. "integrations.plausible") → full API reference with all functions and parameters
- Function (e.g. "integrations.plausible.query_stats") → detailed single-function docs
- Guide (e.g. "overview", "examples") → supplementary documentation

Always use lua_read_doc before writing Lua code to confirm function names and parameters.
DESC;
    }

    public function parameters(): array
    {
        return [
            'page' => ['type' => 'string', 'description' => 'Page to read: namespace (e.g. "integrations.plausible"), function path (e.g. "integrations.plausible.query_stats"), or guide name (e.g. "overview", "examples").'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['page'];
    }

    protected function handle(array $args): ToolResult
    {
        $page = $args['page'] ?? '';

        if (trim($page) === '') {
            return ToolResult::error('Missing required parameter "page". Provide a namespace (e.g. "integrations.plausible"), function path (e.g. "integrations.plausible.query_stats"), or guide name (e.g. "overview").');
        }

        $result = $this->docs->readDoc($page);

        return ToolResult::success($result);
    }
}
