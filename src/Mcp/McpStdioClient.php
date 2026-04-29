<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final class McpStdioClient
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $nextId = 1;

    private bool $initialized = false;

    private string $stderr = '';

    public function __construct(
        private readonly McpServerConfig $config,
        private readonly McpSecretStore $secrets,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $this->ensureInitialized();
        $result = $this->request('tools/list');

        return is_array($result['tools'] ?? null) ? $result['tools'] : [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callTool(string $name, array $arguments): mixed
    {
        $this->ensureInitialized();

        return $this->request('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listResources(): array
    {
        $this->ensureInitialized();
        $result = $this->request('resources/list');

        return is_array($result['resources'] ?? null) ? $result['resources'] : [];
    }

    public function readResource(string $uri): mixed
    {
        $this->ensureInitialized();

        return $this->request('resources/read', ['uri' => $uri]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPrompts(): array
    {
        $this->ensureInitialized();
        $result = $this->request('prompts/list');

        return is_array($result['prompts'] ?? null) ? $result['prompts'] : [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function getPrompt(string $name, array $arguments = []): mixed
    {
        $this->ensureInitialized();

        return $this->request('prompts/get', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
        $this->pipes = [];
        $this->initialized = false;
    }

    public function stderr(): string
    {
        return trim($this->stderr);
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->start();
        $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'kosmokrator',
                'version' => 'dev',
            ],
        ]);
        $this->notification('notifications/initialized');
        $this->initialized = true;
    }

    private function start(): void
    {
        if ($this->process !== null) {
            return;
        }

        if ($this->config->type !== 'stdio') {
            throw new \RuntimeException("MCP server '{$this->config->name}' uses unsupported transport '{$this->config->type}'. Stdio is implemented first; Streamable HTTP support is planned.");
        }

        if ($this->config->command === null || $this->config->command === '') {
            throw new \RuntimeException("MCP server '{$this->config->name}' is missing a command.");
        }

        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($this->config->env as $key => $value) {
            $env[$key] = $this->secrets->resolveValue($this->config->name, $value);
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = array_merge([$this->config->command], $this->config->args);
        $this->process = proc_open($command, $descriptor, $this->pipes, null, $env);

        if (! is_resource($this->process)) {
            throw new \RuntimeException("Failed to start MCP server '{$this->config->name}'.");
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        $id = $this->nextId++;
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if ($params !== []) {
            $message['params'] = $params;
        }

        $this->write($message);

        return $this->readResponse($id);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function notification(string $method, array $params = []): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params !== []) {
            $message['params'] = $params;
        }

        $this->write($message);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function write(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MCP request.');
        }

        fwrite($this->pipes[0], $json."\n");
        fflush($this->pipes[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readResponse(int $id): array
    {
        $deadline = microtime(true) + $this->config->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            $timeout = max(0, (int) floor($deadline - microtime(true)));
            $ready = @stream_select($read, $write, $except, $timeout, 100000);

            if ($ready === false) {
                throw new \RuntimeException("Failed while reading MCP server '{$this->config->name}'.");
            }

            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $this->pipes[2]) {
                    $this->stderr .= (string) stream_get_contents($stream);

                    continue;
                }

                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $decoded = json_decode($line, true);
                    if (! is_array($decoded)) {
                        throw new \RuntimeException("MCP server '{$this->config->name}' wrote invalid JSON to stdout.");
                    }

                    if (($decoded['id'] ?? null) !== $id) {
                        continue;
                    }

                    if (isset($decoded['error']) && is_array($decoded['error'])) {
                        $message = (string) ($decoded['error']['message'] ?? 'MCP request failed.');
                        throw new \RuntimeException($message);
                    }

                    $result = $decoded['result'] ?? [];

                    return is_array($result) ? $result : ['value' => $result];
                }
            }

            $status = proc_get_status($this->process);
            if (! ($status['running'] ?? false)) {
                $this->stderr .= is_resource($this->pipes[2]) ? (string) stream_get_contents($this->pipes[2]) : '';
                throw new \RuntimeException("MCP server '{$this->config->name}' exited before responding.".($this->stderr() !== '' ? ' Stderr: '.$this->stderr() : ''));
            }
        }

        throw new \RuntimeException("MCP server '{$this->config->name}' timed out after {$this->config->timeoutSeconds}s.");
    }
}
