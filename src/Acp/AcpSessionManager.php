<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Session\SessionManager;

final class AcpSessionManager
{
    /** @var array<string, AcpSessionState> */
    private array $states = [];

    public function __construct(
        private readonly Container $container,
        private readonly AcpConnection $connection,
        private readonly ?string $model = null,
        private readonly ?string $mode = null,
        private readonly ?string $permissionMode = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $mcpServers
     */
    public function create(string $cwd, array $mcpServers = []): AcpSessionState
    {
        $state = $this->buildState($cwd, null, $mcpServers);
        $modelName = $state->session->llm->getProvider().'/'.$state->session->llm->getModel();
        $id = $state->session->sessionManager->createSession($modelName);

        $state = new AcpSessionState($id, $cwd, $state->session, $state->renderer, $mcpServers);
        $state->renderer->setSessionId($id);
        $this->states[$id] = $state;

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $mcpServers
     */
    public function load(string $sessionId, string $cwd, array $mcpServers = []): AcpSessionState
    {
        if (isset($this->states[$sessionId])) {
            return $this->states[$sessionId];
        }

        $state = $this->buildState($cwd, $sessionId, $mcpServers);
        $state->session->sessionManager->setCurrentSession($sessionId);
        $history = $state->session->sessionManager->loadHistory($sessionId);
        if ($history->count() > 0) {
            $state->session->agentLoop->setHistory($history);
            $state->renderer->replayHistory($history->messages());
        }

        $state = new AcpSessionState($sessionId, $cwd, $state->session, $state->renderer, $mcpServers);
        $state->renderer->setSessionId($sessionId);
        $this->states[$sessionId] = $state;

        return $state;
    }

    public function get(string $sessionId): AcpSessionState
    {
        if (! isset($this->states[$sessionId])) {
            throw JsonRpcException::invalidParams("Session not loaded: {$sessionId}");
        }

        $this->activateRuntime($this->states[$sessionId]);

        return $this->states[$sessionId];
    }

    public function close(string $sessionId): void
    {
        if (isset($this->states[$sessionId])) {
            $this->states[$sessionId]->session->orchestrator?->cancelAll();
            unset($this->states[$sessionId]);
        }
    }

    public function cancel(string $sessionId): void
    {
        if (! isset($this->states[$sessionId])) {
            return;
        }

        $this->states[$sessionId]->renderer->cancel();
        $this->states[$sessionId]->session->orchestrator?->cancelAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $cwd, ?string $cursor = null, int $limit = 100): array
    {
        $sessionManager = $this->container->make(SessionManager::class);
        $sessionManager->setProject($cwd);

        $rows = $sessionManager->listSessions($limit + 1);
        if ($cursor !== null && $cursor !== '') {
            $offset = 0;
            foreach ($rows as $idx => $row) {
                if (($row['id'] ?? null) === $cursor) {
                    $offset = $idx + 1;
                    break;
                }
            }
            $rows = array_slice($rows, $offset);
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $mcpServers
     */
    private function buildState(string $cwd, ?string $sessionId, array $mcpServers): AcpSessionState
    {
        $previousCwd = getcwd() ?: null;
        if (is_dir($cwd)) {
            chdir($cwd);
        }

        try {
            $renderer = new AcpRenderer($this->connection);
            $this->applyMcpServers($mcpServers, $cwd);
            $builder = new AgentSessionBuilder($this->container);
            $session = $builder->buildGateway($renderer, $this->buildOptions());
            $session->sessionManager->setProject($cwd);

            if ($sessionId !== null) {
                $renderer->setSessionId($sessionId);
            }

            return new AcpSessionState($sessionId ?? '', $cwd, $session, $renderer, $mcpServers);
        } catch (\RuntimeException $e) {
            throw JsonRpcException::authRequired($e->getMessage());
        } finally {
            if ($previousCwd !== null && is_dir($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(): array
    {
        return array_filter([
            'model' => $this->model,
            'agent_mode' => $this->mode,
            'permission_mode' => $this->permissionMode,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  list<array<string, mixed>>  $mcpServers
     */
    private function applyMcpServers(array $mcpServers, string $cwd): void
    {
        if (! $this->container->bound(McpConfigStore::class)) {
            return;
        }

        $store = $this->container->make(McpConfigStore::class);
        $store->setProjectRoot($cwd);
        $runtimeServers = [];
        foreach ($mcpServers as $server) {
            if (! is_array($server)) {
                continue;
            }

            $name = (string) ($server['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (isset($server['command'])) {
                $runtimeServers[$name] = McpServerConfig::fromArray($name, [
                    'type' => 'stdio',
                    'command' => (string) $server['command'],
                    'args' => array_values(array_map('strval', is_array($server['args'] ?? null) ? $server['args'] : [])),
                    'env' => $this->envListToMap($server['env'] ?? []),
                    'enabled' => true,
                ], 'acp');
            }
        }

        $store->setRuntimeServers($runtimeServers);
        $this->resetMcpRuntime();
    }

    private function activateRuntime(AcpSessionState $state): void
    {
        if ($this->container->bound(McpConfigStore::class)) {
            $this->container->make(McpConfigStore::class)->setProjectRoot($state->cwd);
        }

        $this->applyMcpServers($state->mcpServers, $state->cwd);
    }

    private function resetMcpRuntime(): void
    {
        if ($this->container->bound(McpClientManager::class)) {
            $this->container->make(McpClientManager::class)->closeAll();
        }

        if ($this->container->bound(McpCatalog::class)) {
            $this->container->make(McpCatalog::class)->clearCache();
        }
    }

    /**
     * @return array<string, string>
     */
    private function envListToMap(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $map = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name'])) {
                $map[(string) $item['name']] = (string) ($item['value'] ?? '');
            }
        }

        return $map;
    }
}
