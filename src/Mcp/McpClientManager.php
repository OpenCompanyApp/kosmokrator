<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final class McpClientManager
{
    /** @var array<string, McpStdioClient> */
    private array $clients = [];

    public function __construct(
        private readonly McpConfigStore $configs,
        private readonly McpSecretStore $secrets,
    ) {}

    public function client(string $server): McpStdioClient
    {
        if (isset($this->clients[$server])) {
            return $this->clients[$server];
        }

        $config = $this->configs->get($server);
        if ($config === null) {
            throw new \RuntimeException("Unknown MCP server: {$server}");
        }

        if (! $config->enabled) {
            throw new \RuntimeException("MCP server '{$server}' is disabled.");
        }

        return $this->clients[$server] = new McpStdioClient($config, $this->secrets);
    }

    public function closeAll(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];
    }
}
