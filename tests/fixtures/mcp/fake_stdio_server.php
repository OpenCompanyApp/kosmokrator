#!/usr/bin/env php
<?php

declare(strict_types=1);

fwrite(STDERR, "fake mcp ready\n");

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode(trim($line), true);
    if (! is_array($request)) {
        continue;
    }

    if (! isset($request['id'])) {
        continue;
    }

    $id = $request['id'];
    $method = $request['method'] ?? '';
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];

    try {
        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [
                    'tools' => new stdClass,
                    'resources' => new stdClass,
                    'prompts' => new stdClass,
                ],
                'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
            ],
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'echo',
                        'description' => 'Echo a message.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'description' => 'Message to echo'],
                            ],
                            'required' => ['message'],
                        ],
                        'annotations' => ['readOnlyHint' => true],
                    ],
                    [
                        'name' => 'create_issue',
                        'description' => 'Create an issue.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                            ],
                            'required' => ['title'],
                        ],
                    ],
                ],
            ],
            'tools/call' => call_tool($params),
            'resources/list' => [
                'resources' => [
                    ['uri' => 'fake://readme', 'name' => 'Readme', 'mimeType' => 'text/plain'],
                ],
            ],
            'resources/read' => [
                'contents' => [
                    ['uri' => $params['uri'] ?? 'fake://readme', 'mimeType' => 'text/plain', 'text' => 'fake resource'],
                ],
            ],
            'prompts/list' => [
                'prompts' => [
                    ['name' => 'summarize', 'description' => 'Summarize text'],
                ],
            ],
            'prompts/get' => [
                'messages' => [
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'summarize '.json_encode($params['arguments'] ?? [])]],
                ],
            ],
            default => throw new RuntimeException("Unknown method {$method}"),
        };

        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES)."\n";
    } catch (Throwable $e) {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => $e->getMessage()]], JSON_UNESCAPED_SLASHES)."\n";
    }
}

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function call_tool(array $params): array
{
    $name = (string) ($params['name'] ?? '');
    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

    return match ($name) {
        'echo' => ['content' => [['type' => 'text', 'text' => (string) ($args['message'] ?? '')]]],
        'create_issue' => ['content' => [['type' => 'text', 'text' => json_encode(['created' => true, 'title' => $args['title'] ?? ''])]]],
        default => ['isError' => true, 'content' => [['type' => 'text', 'text' => "Unknown tool {$name}"]]],
    };
}
