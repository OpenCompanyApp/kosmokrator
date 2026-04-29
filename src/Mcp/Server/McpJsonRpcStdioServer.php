<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

final class McpJsonRpcStdioServer
{
    public function __construct(
        private readonly KosmokratorMcpGateway $gateway,
        private readonly McpGatewayProfile $profile,
        private readonly string $version = 'dev',
    ) {}

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function run(mixed $input, mixed $output): int
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            if (! is_array($request)) {
                $this->write($output, $this->error(null, -32700, 'Parse error'));

                continue;
            }

            $id = $request['id'] ?? null;
            $method = (string) ($request['method'] ?? '');
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];

            try {
                $result = $this->handle($method, $params);
            } catch (McpMethodNotFoundException $e) {
                if ($id !== null) {
                    $this->write($output, $this->error($id, -32601, $e->getMessage()));
                }

                continue;
            } catch (\InvalidArgumentException $e) {
                if ($id !== null) {
                    $this->write($output, $this->error($id, -32602, $e->getMessage()));
                }

                continue;
            } catch (\Throwable $e) {
                if ($id !== null) {
                    $this->write($output, $this->error($id, -32603, $e->getMessage()));
                }

                continue;
            }

            if ($id === null) {
                continue;
            }

            $this->write($output, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ]);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handle(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => (string) ($params['protocolVersion'] ?? '2025-11-25'),
                'capabilities' => [
                    'tools' => (object) [],
                    'resources' => ['subscribe' => false, 'listChanged' => false],
                    'prompts' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'kosmokrator',
                    'version' => $this->version,
                ],
            ],
            'tools/list' => ['tools' => $this->gateway->tools($this->profile)],
            'tools/call' => $this->toolsCall($params),
            'resources/list' => ['resources' => $this->gateway->resources($this->profile)],
            'resources/read' => $this->resourceRead($params),
            'prompts/list' => ['prompts' => $this->gateway->prompts($this->profile)],
            'prompts/get' => $this->promptGet($params),
            'notifications/initialized' => [],
            default => throw new McpMethodNotFoundException("Method not found: {$method}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('tools/call requires params.name.');
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            throw new \InvalidArgumentException('tools/call params.arguments must be an object.');
        }

        return $this->gateway->callTool($this->profile, $name, $arguments);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resourceRead(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            throw new \InvalidArgumentException('resources/read requires params.uri.');
        }

        return $this->gateway->readResource($this->profile, $uri);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function promptGet(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('prompts/get requires params.name.');
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return $this->gateway->getPrompt($this->profile, $name, $arguments);
    }

    /**
     * @param  resource  $output
     * @param  array<string, mixed>  $message
     */
    private function write(mixed $output, array $message): void
    {
        fwrite($output, (json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')."\n");
        fflush($output);
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
