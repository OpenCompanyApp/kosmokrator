<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\Integration\Runtime\IntegrationFunction;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\Runtime\IntegrationRuntimeOptions;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Mcp\McpToolDefinition;

final class KosmokratorMcpGateway
{
    public function __construct(
        private readonly IntegrationCatalog $integrations,
        private readonly IntegrationRuntime $integrationRuntime,
        private readonly McpCatalog $mcpCatalog,
        private readonly McpRuntime $mcpRuntime,
        private readonly McpConfigStore $mcpConfigs,
        private readonly McpPermissionEvaluator $mcpPermissions,
        private readonly McpGatewayResultFormatter $formatter = new McpGatewayResultFormatter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function tools(McpGatewayProfile $profile): array
    {
        $tools = [];

        foreach ($this->integrationFunctions($profile) as $function) {
            $operation = $function->operation === 'read' ? 'read' : 'write';
            if ($operation === 'write' && ! $profile->allowsWrites()) {
                continue;
            }

            $hydrated = $this->integrations->hydrate($function);
            $tools[] = [
                'name' => McpGatewayToolMapper::integrationToolName($hydrated->provider, $hydrated->function),
                'description' => trim($hydrated->description) !== ''
                    ? $hydrated->description
                    : "KosmoKrator {$hydrated->provider}.{$hydrated->function} integration.",
                'inputSchema' => $hydrated->inputSchema(),
                'annotations' => $this->annotations($operation),
            ];
        }

        foreach ($this->upstreamTools($profile) as $tool) {
            $operation = $tool->operation();
            if ($operation === 'write' && ! $profile->allowsWrites()) {
                continue;
            }

            $tools[] = [
                'name' => McpGatewayToolMapper::upstreamToolName($tool->server, $tool->luaName),
                'description' => trim($tool->description) !== ''
                    ? $tool->description
                    : "KosmoKrator proxied {$tool->server}.{$tool->name} MCP tool.",
                'inputSchema' => $tool->inputSchema,
                'annotations' => $this->annotations($operation),
            ];
        }

        usort($tools, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(McpGatewayProfile $profile, string $name, array $arguments): array
    {
        $parsed = McpGatewayToolMapper::parse($name);
        if ($parsed === null) {
            return $this->formatter->error("Unknown KosmoKrator gateway tool: {$name}", $profile->maxResultChars);
        }

        try {
            if ($parsed['kind'] === 'integration') {
                return $this->callIntegrationTool($profile, $parsed['namespace'], $parsed['function'], $arguments);
            }

            return $this->callUpstreamTool($profile, $parsed['namespace'], $parsed['function'], $arguments);
        } catch (\Throwable $e) {
            return $this->formatter->error($e->getMessage(), $profile->maxResultChars);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resources(McpGatewayProfile $profile): array
    {
        if (! $profile->exposeResources) {
            return [];
        }

        $helper = $this->mcpRuntime->helperFunctions($profile->force)['mcp.resources'];
        $resources = [];

        foreach ($this->selectedUpstreamServers($profile) as $server) {
            try {
                foreach ($helper($server->name) as $resource) {
                    if (! is_array($resource)) {
                        continue;
                    }
                    $uri = (string) ($resource['uri'] ?? '');
                    if ($uri === '') {
                        continue;
                    }
                    $resource['uri'] = $this->gatewayResourceUri($server->name, $uri);
                    $resource['name'] = (string) ($resource['name'] ?? "{$server->name}: {$uri}");
                    $resources[] = $resource;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    public function readResource(McpGatewayProfile $profile, string $uri): array
    {
        $decoded = $this->decodeGatewayResourceUri($uri);
        if ($decoded === null) {
            throw new \RuntimeException("Unknown KosmoKrator gateway resource URI: {$uri}");
        }

        $helper = $this->mcpRuntime->helperFunctions($profile->force)['mcp.read_resource'];
        $result = $helper($decoded['server'], $decoded['uri']);

        return is_array($result) ? $result : ['contents' => [['uri' => $uri, 'text' => (string) $result]]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prompts(McpGatewayProfile $profile): array
    {
        if (! $profile->exposePrompts) {
            return [];
        }

        $helper = $this->mcpRuntime->helperFunctions($profile->force)['mcp.prompts'];
        $prompts = [];

        foreach ($this->selectedUpstreamServers($profile) as $server) {
            try {
                foreach ($helper($server->name) as $prompt) {
                    if (! is_array($prompt)) {
                        continue;
                    }
                    $name = (string) ($prompt['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $prompt['name'] = McpGatewayToolMapper::upstreamToolName($server->name, $name);
                    $prompts[] = $prompt;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $prompts;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getPrompt(McpGatewayProfile $profile, string $name, array $arguments = []): array
    {
        $parsed = McpGatewayToolMapper::parse($name);
        if ($parsed === null || $parsed['kind'] !== 'mcp') {
            throw new \RuntimeException("Unknown KosmoKrator gateway prompt: {$name}");
        }

        $server = $this->findSelectedUpstreamServer($profile, $parsed['namespace']);
        if ($server === null) {
            throw new \RuntimeException("MCP server '{$parsed['namespace']}' is not exposed by this KosmoKrator MCP gateway profile.");
        }

        $prompt = $this->findUpstreamPrompt($profile, $server->name, $parsed['function']);
        if ($prompt === null) {
            throw new \RuntimeException("Unknown KosmoKrator gateway prompt: {$name}");
        }

        $helper = $this->mcpRuntime->helperFunctions($profile->force)['mcp.get_prompt'];
        $result = $helper($server->name, $prompt, $arguments);

        return is_array($result) ? $result : ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) $result]]]]];
    }

    /**
     * @return list<IntegrationFunction>
     */
    private function integrationFunctions(McpGatewayProfile $profile): array
    {
        $functions = [];
        $selected = $this->selectedIdentifierMap($profile->integrations);

        foreach ($this->integrations->byProvider() as $provider => $providerFunctions) {
            if (! isset($selected[$provider]) && ! isset($selected[McpGatewayToolMapper::identifier($provider)])) {
                continue;
            }

            foreach ($providerFunctions as $function) {
                if (! $function->active || ! $function->configured || ($function->capabilities['cli_runtime_supported'] ?? true) !== true) {
                    continue;
                }
                $functions[] = $function;
            }
        }

        return $functions;
    }

    /**
     * @return list<McpToolDefinition>
     */
    private function upstreamTools(McpGatewayProfile $profile): array
    {
        $tools = [];

        foreach ($this->selectedUpstreamServers($profile) as $server) {
            if (! $this->mcpPermissions->isTrusted($server, $profile->force)) {
                continue;
            }

            try {
                array_push($tools, ...$this->mcpCatalog->tools($server->name));
            } catch (\Throwable) {
                continue;
            }
        }

        return $tools;
    }

    /**
     * @return list<McpServerConfig>
     */
    private function selectedUpstreamServers(McpGatewayProfile $profile): array
    {
        $selected = $this->selectedIdentifierMap($profile->upstreams);
        $servers = [];

        foreach ($this->mcpConfigs->effectiveServers(false) as $server) {
            if (
                (! isset($selected[$server->name]) && ! isset($selected[McpGatewayToolMapper::identifier($server->name)]))
                || $this->isSelfServer($server)
            ) {
                continue;
            }

            $servers[] = $server;
        }

        return $servers;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callIntegrationTool(McpGatewayProfile $profile, string $provider, string $function, array $arguments): array
    {
        $definition = $this->findIntegrationFunction($profile, $provider, $function);
        if ($definition === null && ! $this->isSelected($profile->integrations, $provider)) {
            return $this->formatter->error("Integration '{$provider}' is not exposed by this KosmoKrator MCP gateway profile.", $profile->maxResultChars);
        }

        if ($definition === null) {
            return $this->formatter->error("Unknown integration function: {$provider}.{$function}", $profile->maxResultChars);
        }

        $name = $definition->fullName();
        $operation = $definition->operation === 'read' ? 'read' : 'write';
        if ($operation === 'write' && ! $profile->allowsWrites()) {
            return $this->formatter->error("Integration function '{$name}' is write-capable and this gateway profile has write_policy=deny.", $profile->maxResultChars);
        }

        $account = null;
        if (isset($arguments['_account']) && is_scalar($arguments['_account'])) {
            $account = (string) $arguments['_account'];
            unset($arguments['_account']);
        }

        $result = $this->integrationRuntime->call(
            $name,
            $arguments,
            $account,
            new IntegrationRuntimeOptions(account: $account, force: $profile->allowsWrites() || $profile->force),
        );

        if (! $result->success) {
            return $this->formatter->error((string) ($result->error ?? 'Integration call failed.'), $profile->maxResultChars);
        }

        return $this->formatter->success($result->data, $profile->maxResultChars);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callUpstreamTool(McpGatewayProfile $profile, string $server, string $tool, array $arguments): array
    {
        $serverConfig = $this->findSelectedUpstreamServer($profile, $server);
        if ($serverConfig === null && ! $this->isSelected($profile->upstreams, $server)) {
            return $this->formatter->error("MCP server '{$server}' is not exposed by this KosmoKrator MCP gateway profile.", $profile->maxResultChars);
        }

        if ($serverConfig === null) {
            return $this->formatter->error("Unknown MCP server: {$server}", $profile->maxResultChars);
        }

        $definition = $this->findUpstreamTool($serverConfig->name, $tool);
        if ($definition === null) {
            return $this->formatter->error("Unknown MCP function: {$server}.{$tool}", $profile->maxResultChars);
        }

        if ($definition->operation() === 'write' && ! $profile->allowsWrites()) {
            return $this->formatter->error("MCP function '{$serverConfig->name}.{$definition->luaName}' is write-capable and this gateway profile has write_policy=deny.", $profile->maxResultChars);
        }

        $result = $this->mcpRuntime->call("{$serverConfig->name}.{$definition->luaName}", $arguments, $profile->allowsWrites() || $profile->force);
        if (($result['success'] ?? false) !== true) {
            return $this->formatter->error((string) ($result['error'] ?? 'MCP call failed.'), $profile->maxResultChars);
        }

        return $this->formatter->success($result['data'] ?? null, $profile->maxResultChars);
    }

    /**
     * @return array<string, bool>
     */
    private function annotations(string $operation): array
    {
        return [
            'readOnlyHint' => $operation === 'read',
            'destructiveHint' => $operation !== 'read',
        ];
    }

    private function isSelfServer(McpServerConfig $server): bool
    {
        if ($server->type !== 'stdio') {
            return false;
        }

        $command = basename((string) $server->command);
        $args = implode(' ', $server->args);
        $invocation = $command.' '.$args;

        return preg_match('/\b(kosmo|kosmokrator)\b/', $invocation) === 1
            && str_contains($args, 'mcp:serve');
    }

    /**
     * @param  list<string>  $values
     * @return array<string, true>
     */
    private function selectedIdentifierMap(array $values): array
    {
        $selected = [];
        foreach ($values as $value) {
            $selected[$value] = true;
            $selected[McpGatewayToolMapper::identifier($value)] = true;
        }

        return $selected;
    }

    /**
     * @param  list<string>  $selected
     */
    private function isSelected(array $selected, string $name): bool
    {
        foreach ($selected as $value) {
            if ($value === $name || McpGatewayToolMapper::identifier($value) === $name) {
                return true;
            }
        }

        return false;
    }

    private function findIntegrationFunction(McpGatewayProfile $profile, string $provider, string $function): ?IntegrationFunction
    {
        foreach ($this->integrationFunctions($profile) as $candidate) {
            if (
                McpGatewayToolMapper::identifier($candidate->provider) === $provider
                && McpGatewayToolMapper::identifier($candidate->function) === $function
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function findSelectedUpstreamServer(McpGatewayProfile $profile, string $server): ?McpServerConfig
    {
        foreach ($this->selectedUpstreamServers($profile) as $candidate) {
            if ($candidate->name === $server || McpGatewayToolMapper::identifier($candidate->name) === $server) {
                return $candidate;
            }
        }

        return null;
    }

    private function findUpstreamTool(string $server, string $tool): ?McpToolDefinition
    {
        foreach ($this->mcpCatalog->tools($server) as $candidate) {
            if ($candidate->luaName === $tool || McpGatewayToolMapper::identifier($candidate->name) === $tool) {
                return $candidate;
            }
        }

        return null;
    }

    private function findUpstreamPrompt(McpGatewayProfile $profile, string $server, string $prompt): ?string
    {
        $helper = $this->mcpRuntime->helperFunctions($profile->force)['mcp.prompts'];
        foreach ($helper($server) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $name = (string) ($candidate['name'] ?? '');
            if ($name !== '' && McpGatewayToolMapper::identifier($name) === $prompt) {
                return $name;
            }
        }

        return null;
    }

    private function gatewayResourceUri(string $server, string $uri): string
    {
        return 'kosmo://mcp/'.$server.'/'.rtrim(strtr(base64_encode($uri), '+/', '-_'), '=');
    }

    /**
     * @return array{server: string, uri: string}|null
     */
    private function decodeGatewayResourceUri(string $uri): ?array
    {
        if (preg_match('#^(?:kosmo|kosmokrator)://mcp/([^/]+)/(.+)$#', $uri, $matches) !== 1) {
            return null;
        }

        $encoded = strtr($matches[2], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : ['server' => $matches[1], 'uri' => $decoded];
    }
}
