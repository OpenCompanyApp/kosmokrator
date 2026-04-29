<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\Integration\Runtime\IntegrationFunction;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\Runtime\IntegrationRuntimeOptions;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderConfigurator as LlmProviderConfigurator;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Mcp\McpSecretStore;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Permission\PermissionMode;

final class AcpAgentServer
{
    private AcpSessionManager $sessions;

    public function __construct(
        private readonly Container $container,
        private readonly AcpConnection $connection,
        private readonly string $cwd,
        private readonly string $version,
        ?string $model = null,
        ?string $mode = null,
        ?string $permissionMode = null,
    ) {
        $this->sessions = new AcpSessionManager($container, $connection, $model, $mode, $permissionMode);
        $this->connection->onNotification('session/cancel', fn (array $params) => $this->cancel($params));
        $this->connection->onNotification('cancel', fn (array $params) => $this->cancel($params));
    }

    public function run(): int
    {
        while (($message = $this->connection->readMessage()) !== null) {
            $id = $message['id'] ?? null;
            try {
                if (! isset($message['method']) || ! is_string($message['method'])) {
                    throw JsonRpcException::invalidRequest('JSON-RPC request is missing method');
                }

                $params = $message['params'] ?? [];
                if (! is_array($params)) {
                    throw JsonRpcException::invalidParams('Params must be an object');
                }

                if (! array_key_exists('id', $message)) {
                    $this->connection->dispatchNotification($message);

                    continue;
                }

                $this->connection->sendResult($id, $this->dispatch($message['method'], $params));
            } catch (\Throwable $e) {
                $this->connection->sendError($id, $e);
            }
        }

        $this->container->make(ShellSessionManager::class)->killAll();

        return 0;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function dispatch(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'authenticate' => new \stdClass,
            'session/new' => $this->newSession($params),
            'session/load' => $this->loadSession($params),
            'session/resume', 'session/unstable_resume' => $this->resumeSession($params),
            'session/list' => $this->listSessions($params),
            'session/prompt' => $this->prompt($params),
            'session/cancel' => $this->cancelRequest($params),
            'session/close' => $this->closeSession($params),
            'session/set_mode' => $this->setMode($params),
            'session/set_model', 'session/unstable_set_model' => $this->setModel($params),
            'session/set_config_option' => $this->setConfigOption($params),
            'kosmokrator/capabilities' => ['kosmokratorCapabilities' => AcpKosmokratorProtocol::capabilities()],
            'kosmokrator/integrations/list' => $this->listIntegrations($params),
            'kosmokrator/integrations/describe' => $this->describeIntegration($params),
            'kosmokrator/integrations/call' => $this->callIntegration($params),
            'kosmokrator/mcp/list_servers' => $this->listMcpServers($params),
            'kosmokrator/mcp/list_tools' => $this->listMcpTools($params),
            'kosmokrator/mcp/schema' => $this->mcpSchema($params),
            'kosmokrator/mcp/call_tool' => $this->callMcpTool($params),
            'kosmokrator/lua/execute' => $this->executeLua($params),
            'kosmokrator/runtime/set' => $this->setRuntime($params),
            'kosmokrator/settings/set' => $this->setSetting($params),
            'kosmokrator/providers/configure' => $this->configureProvider($params),
            'kosmokrator/integrations/configure' => $this->configureIntegration($params),
            'kosmokrator/mcp/add_stdio_server' => $this->addMcpStdioServer($params),
            'kosmokrator/mcp/set_secret' => $this->setMcpSecret($params),
            default => throw JsonRpcException::methodNotFound($method),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $protocolVersion = (int) ($params['protocolVersion'] ?? 1);

        return [
            'protocolVersion' => $protocolVersion,
            'agentInfo' => [
                'name' => 'KosmoKrator',
                'version' => $this->version,
            ],
            'agentCapabilities' => [
                'loadSession' => true,
                'promptCapabilities' => [
                    'image' => false,
                    'audio' => false,
                    'embeddedContext' => true,
                ],
                'mcpCapabilities' => [
                    'http' => false,
                    'sse' => false,
                ],
                'sessionCapabilities' => [
                    'list' => new \stdClass,
                    'resume' => new \stdClass,
                    'close' => new \stdClass,
                ],
            ],
            'kosmokratorCapabilities' => AcpKosmokratorProtocol::capabilities(),
            'authMethods' => [
                [
                    'id' => 'kosmokrator-provider',
                    'name' => 'KosmoKrator provider credentials',
                    'description' => 'Configure credentials with `kosmokrator providers:configure` or `kosmokrator setup`.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function newSession(array $params): array
    {
        $cwd = $this->resolveCwd($params['cwd'] ?? $this->cwd);
        $state = $this->sessions->create($cwd, $this->mcpServers($params));

        return [
            'sessionId' => $state->id,
            'modes' => $this->modeState($state),
            'models' => $this->modelState($state),
            'configOptions' => $this->configOptions($state),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function loadSession(array $params): array
    {
        $sessionId = $this->requireString($params, 'sessionId');
        $cwd = $this->resolveCwd($params['cwd'] ?? $this->cwd);
        $state = $this->sessions->load($sessionId, $cwd, $this->mcpServers($params));

        return [
            'sessionId' => $state->id,
            'modes' => $this->modeState($state),
            'models' => $this->modelState($state),
            'configOptions' => $this->configOptions($state),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resumeSession(array $params): array
    {
        return $this->loadSession($params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listSessions(array $params): array
    {
        $cwd = $this->resolveCwd($params['cwd'] ?? $this->cwd);
        $cursor = isset($params['cursor']) ? (string) $params['cursor'] : null;
        $rows = $this->sessions->list($cwd, $cursor, 101);
        $nextCursor = count($rows) > 100 ? (string) ($rows[99]['id'] ?? '') : null;
        $rows = array_slice($rows, 0, 100);
        $sessions = array_map(fn (array $row) => [
            'sessionId' => (string) $row['id'],
            'cwd' => (string) ($row['project'] ?? $cwd),
            'title' => $row['title'] ?? $row['last_user_message'] ?? null,
            'updatedAt' => $this->formatTimestamp($row['updated_at'] ?? null),
        ], $rows);

        return array_filter([
            'sessions' => $sessions,
            'nextCursor' => $nextCursor !== '' ? $nextCursor : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function prompt(array $params): array
    {
        $sessionId = $this->requireString($params, 'sessionId');
        $state = $this->sessions->get($sessionId);
        $text = $this->extractPromptText($params['prompt'] ?? []);
        if (trim($text) === '') {
            return ['stopReason' => 'end_turn'];
        }

        $state->session->sessionManager->setCurrentSession($sessionId);
        $state->renderer->beginTurn();

        try {
            $state->session->agentLoop->run($text);
        } finally {
            $state->renderer->endTurn();
        }

        return [
            'stopReason' => $state->renderer->wasCancelled() ? 'cancelled' : 'end_turn',
            'usage' => [
                'inputTokens' => $state->session->agentLoop->getSessionTokensIn(),
                'outputTokens' => $state->session->agentLoop->getSessionTokensOut(),
                'totalTokens' => $state->session->agentLoop->getSessionTokensIn() + $state->session->agentLoop->getSessionTokensOut(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function closeSession(array $params): \stdClass
    {
        $this->sessions->close($this->requireString($params, 'sessionId'));

        return new \stdClass;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function setMode(array $params): \stdClass
    {
        $state = $this->sessions->get($this->requireString($params, 'sessionId'));
        $mode = AgentMode::from($this->requireString($params, 'modeId'));
        $state->session->agentLoop->setMode($mode);
        $state->renderer->showCurrentMode($mode->value);

        return new \stdClass;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function setModel(array $params): array
    {
        $state = $this->sessions->get($this->requireString($params, 'sessionId'));
        $state->session->llm->setModel($this->normalizeModelId($this->requireString($params, 'modelId')));

        return ['models' => $this->modelState($state), 'configOptions' => $this->configOptions($state)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function setConfigOption(array $params): array
    {
        $state = $this->sessions->get($this->requireString($params, 'sessionId'));
        $configId = $this->requireString($params, 'configId');
        $value = (string) ($params['value'] ?? '');

        match ($configId) {
            'model' => $state->session->llm->setModel($this->normalizeModelId($value)),
            'mode' => $this->applyMode($state, $value),
            'permission_mode' => $state->session->permissions->setPermissionMode(PermissionMode::from($value)),
            default => throw JsonRpcException::invalidParams("Unknown config option: {$configId}"),
        };

        return ['configOptions' => $this->configOptions($state)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function setRuntime(array $params): array
    {
        $state = $this->sessions->get($this->requireString($params, 'sessionId'));

        if (isset($params['mode'])) {
            $this->applyMode($state, (string) $params['mode']);
        }
        $provider = isset($params['provider']) && is_string($params['provider']) && $params['provider'] !== ''
            ? $params['provider']
            : null;
        if (isset($params['model'])) {
            [$providerFromModel, $model] = $this->splitModelId((string) $params['model']);
            $provider ??= $providerFromModel;
            if ($provider !== null) {
                $state->session->llm->setProvider($provider);
            }
            $state->session->llm->setModel($model);
        } elseif ($provider !== null) {
            $state->session->llm->setProvider($provider);
        }
        if (isset($params['permissionMode'])) {
            $state->session->permissions->setPermissionMode(PermissionMode::from((string) $params['permissionMode']));
        }

        $payload = [
            'modes' => $this->modeState($state),
            'models' => $this->modelState($state),
            'configOptions' => $this->configOptions($state),
        ];
        $state->renderer->emitKosmokratorEvent('runtime_changed', $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function setSetting(array $params): array
    {
        $state = $this->runtimeState($params);
        $path = $this->requireString($params, 'path');
        $value = $params['value'] ?? '';
        $scope = $this->scope($params);

        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($state->cwd);
        $settings->setRaw($path, $value, $scope);

        $result = ['path' => $path, 'scope' => $scope, 'configured' => true];
        $state->renderer->emitKosmokratorEvent('runtime_changed', ['operation' => 'setting_set', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function configureProvider(array $params): array
    {
        $state = $this->runtimeState($params);
        $provider = $this->requireString($params, 'provider');
        $scope = $this->scope($params);
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($state->cwd);
        $model = isset($params['model']) && is_string($params['model']) && $params['model'] !== ''
            ? $this->normalizeModelId($params['model'])
            : null;
        $apiKey = isset($params['apiKey']) && is_string($params['apiKey']) ? $params['apiKey'] : null;

        $status = $this->container->make(LlmProviderConfigurator::class)->configure(
            provider: $provider,
            model: $model,
            apiKey: $apiKey,
            scope: $scope,
        );

        if (isset($params['baseUrl']) && is_string($params['baseUrl'])) {
            $settings->setRaw("prism.providers.{$provider}.url", $params['baseUrl'], $scope);
        }

        $result = [
            'provider' => $provider,
            'scope' => $scope,
            'configured' => true,
            'hasApiKey' => $apiKey !== null && $apiKey !== '',
            'baseUrlConfigured' => isset($params['baseUrl']) && is_string($params['baseUrl']) && $params['baseUrl'] !== '',
            'model' => $model,
        ] + $status;
        $state->renderer->emitKosmokratorEvent('runtime_changed', ['operation' => 'provider_configured', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function configureIntegration(array $params): array
    {
        $state = $this->runtimeState($params);
        $integration = $this->requireString($params, 'integration');
        $account = isset($params['account']) && is_string($params['account']) && $params['account'] !== '' ? $params['account'] : 'default';
        $credentials = $this->scalarMap($this->objectParam($params, 'credentials'));
        $permissions = $this->permissionMap($this->objectParam($params, 'permissions'));
        $enabledProvided = array_key_exists('enabled', $params);
        $enabled = ($params['enabled'] ?? false) === true;
        $scope = $this->scope($params);

        $resolver = $this->container->make(YamlCredentialResolver::class);
        $manager = $this->container->make(IntegrationManager::class);
        $accountArg = $account === 'default' ? null : $account;
        if ($credentials !== []) {
            $resolver->registerAccount($integration, $account);
            foreach ($credentials as $key => $value) {
                $resolver->set($integration, $key, (string) $value, $accountArg);
            }
        }
        if ($enabledProvided) {
            $manager->setEnabled($integration, $enabled, $scope);
        }
        foreach ($permissions as $operation => $permission) {
            $manager->setPermission($integration, $operation, $permission, $scope);
        }
        $this->container->make(IntegrationCatalog::class)->clearCache();

        $result = [
            'integration' => $integration,
            'account' => $account,
            'scope' => $scope,
            'enabled' => $manager->isEnabled($integration),
            'enabledChanged' => $enabledProvided,
            'credentialScope' => 'global',
            'credentialKeys' => array_keys($credentials),
            'permissions' => $permissions,
        ];
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'configured', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function addMcpStdioServer(array $params): array
    {
        $state = $this->runtimeState($params);
        $name = $this->requireString($params, 'name');
        $command = $this->requireString($params, 'command');
        $scope = $this->scope($params);
        $permissions = $this->permissionMap($this->objectParam($params, 'permissions'));
        $server = new McpServerConfig(
            name: $name,
            type: 'stdio',
            command: $command,
            args: array_values(array_map('strval', is_array($params['args'] ?? null) ? $params['args'] : [])),
            env: $this->stringMap(is_array($params['env'] ?? null) ? $params['env'] : []),
            enabled: ($params['enabled'] ?? true) !== false,
            source: $scope,
        );

        $store = $this->container->make(McpConfigStore::class);
        $store->setProjectRoot($state->cwd);
        $path = $store->writeServer($server, $scope);

        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($state->cwd);
        if (($params['trust'] ?? false) === true) {
            $settings->setRaw("mcp.trust.{$name}.fingerprint", $this->container->make(McpPermissionEvaluator::class)->fingerprint($server), $scope);
        }
        foreach ($permissions as $operation => $permission) {
            $settings->setRaw("mcp.servers.{$name}.permissions.{$operation}", $permission, $scope);
        }
        $this->sessions->refreshRuntime($state);

        $result = [
            'name' => $name,
            'scope' => $scope,
            'path' => $path,
            'enabled' => $server->enabled,
            'trusted' => ($params['trust'] ?? false) === true,
            'permissions' => $permissions,
        ];
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'server_configured', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function setMcpSecret(array $params): array
    {
        $state = $this->runtimeState($params);
        $server = $this->requireString($params, 'server');
        $key = $this->requireString($params, 'key');
        $value = $this->requireString($params, 'value');

        $this->container->make(McpSecretStore::class)->set($server, $key, $value);

        $result = ['server' => $server, 'key' => $key, 'configured' => true];
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'secret_set', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listIntegrations(array $params): array
    {
        $state = $this->runtimeState($params);
        $catalog = $this->container->make(IntegrationCatalog::class);
        $query = trim((string) ($params['query'] ?? ''));
        $limit = max(1, min(500, (int) ($params['limit'] ?? 100)));
        $functions = $query === ''
            ? array_slice(array_values($catalog->functions()), 0, $limit)
            : $catalog->search($query, $limit);

        $result = [
            'functions' => array_map(fn (IntegrationFunction $function): array => $function->toArray(), $functions),
            'providers' => $catalog->providers(),
            'locallyRunnableProviders' => $catalog->locallyRunnableProviderNames(),
        ];
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'list', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function describeIntegration(array $params): array
    {
        $state = $this->runtimeState($params);
        $name = $this->runtimeName($params);
        $catalog = $this->container->make(IntegrationCatalog::class);
        $function = $catalog->get($name);
        if (! $function instanceof IntegrationFunction) {
            throw JsonRpcException::invalidParams("Unknown integration function: {$name}");
        }

        $result = $catalog->hydrate($function)->toArray();
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'describe', 'function' => $name, 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callIntegration(array $params): array
    {
        $state = $this->runtimeState($params);
        $name = $this->runtimeName($params);
        $args = $this->objectParam($params, 'args');
        $account = isset($params['account']) && is_string($params['account']) && $params['account'] !== '' ? $params['account'] : null;
        $force = (bool) ($params['force'] ?? false);
        $dryRun = (bool) ($params['dryRun'] ?? false);

        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'call_started', 'function' => $name, 'args' => $args, 'account' => $account, 'force' => $force, 'dryRun' => $dryRun]);
        $result = $this->container->make(IntegrationRuntime::class)
            ->call($name, $args, $account, new IntegrationRuntimeOptions($account, $force, $dryRun))
            ->toArray();
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'call_completed', 'function' => $name, 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listMcpServers(array $params): array
    {
        $state = $this->runtimeState($params);
        $runtime = $this->container->make(McpRuntime::class);
        $helpers = $runtime->helperFunctions((bool) ($params['force'] ?? false));
        $servers = ($helpers['mcp.servers'])();
        $result = ['servers' => is_array($servers) ? array_values($servers) : []];
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'list_servers', 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listMcpTools(array $params): array
    {
        $state = $this->runtimeState($params);
        $runtime = $this->container->make(McpRuntime::class);
        $helpers = $runtime->helperFunctions((bool) ($params['force'] ?? false));
        $server = isset($params['server']) && is_string($params['server']) && $params['server'] !== '' ? $params['server'] : null;
        $tools = ($helpers['mcp.tools'])($server);
        $result = ['tools' => is_array($tools) ? array_values($tools) : []];
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'list_tools', 'server' => $server, 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function mcpSchema(array $params): array
    {
        $state = $this->runtimeState($params);
        $name = $this->runtimeName($params);
        $runtime = $this->container->make(McpRuntime::class);
        $helpers = $runtime->helperFunctions((bool) ($params['force'] ?? false));
        $result = ($helpers['mcp.schema'])($name);
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'schema', 'function' => $name, 'result' => $result]);

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callMcpTool(array $params): array
    {
        $state = $this->runtimeState($params);
        $name = $this->runtimeName($params);
        $args = $this->objectParam($params, 'args');
        $force = (bool) ($params['force'] ?? false);
        $dryRun = (bool) ($params['dryRun'] ?? false);

        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'call_started', 'function' => $name, 'args' => $args, 'force' => $force, 'dryRun' => $dryRun]);
        $result = $this->container->make(McpRuntime::class)->call($name, $args, $force, $dryRun);
        $state->renderer->emitKosmokratorEvent('mcp_event', ['operation' => 'call_completed', 'function' => $name, 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function executeLua(array $params): array
    {
        $state = $this->runtimeState($params);
        $code = isset($params['code']) && is_string($params['code']) ? $params['code'] : '';
        if ($code === '' && isset($params['path']) && is_string($params['path'])) {
            $path = $params['path'];
            if (! str_starts_with($path, '/')) {
                $path = rtrim($state->cwd, '/').'/'.$path;
            }
            $path = $this->resolveCwd($path);
            if (! is_file($path) || ! is_readable($path)) {
                throw JsonRpcException::invalidParams("Lua file is not readable: {$path}");
            }
            $code = (string) file_get_contents($path);
        }
        if (trim($code) === '') {
            throw JsonRpcException::invalidParams('Missing Lua code or path');
        }

        $options = $this->objectParam($params, 'options');
        if (isset($params['force'])) {
            $options['force'] = (bool) $params['force'];
        }
        $runtime = (string) ($params['runtime'] ?? 'integrations');
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'lua_started', 'runtime' => $runtime]);
        $result = match ($runtime) {
            'mcp' => $this->container->make(McpRuntime::class)->executeLua($code, $options)->toArray(),
            'integrations', 'integration', 'all' => $this->container->make(IntegrationRuntime::class)->executeLua($code, $options)->toArray(),
            default => throw JsonRpcException::invalidParams("Unknown Lua runtime: {$runtime}"),
        };
        $state->renderer->emitKosmokratorEvent('integration_event', ['operation' => 'lua_completed', 'runtime' => $runtime, 'result' => $result]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function cancel(array $params): void
    {
        $sessionId = isset($params['sessionId']) ? (string) $params['sessionId'] : '';
        if ($sessionId !== '') {
            $this->sessions->cancel($sessionId);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function cancelRequest(array $params): \stdClass
    {
        $this->cancel($params);

        return new \stdClass;
    }

    private function resolveCwd(mixed $value): string
    {
        $cwd = is_string($value) && $value !== '' ? $value : $this->cwd;
        $real = realpath($cwd);

        return $real !== false ? $real : $cwd;
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return gmdate('c', (int) floor((float) $value));
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function requireString(array $params, string $key): string
    {
        if (! isset($params[$key]) || ! is_string($params[$key]) || $params[$key] === '') {
            throw JsonRpcException::invalidParams("Missing string param: {$key}");
        }

        return $params[$key];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function runtimeState(array $params): AcpSessionState
    {
        return $this->sessions->get($this->requireString($params, 'sessionId'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function runtimeName(array $params): string
    {
        foreach (['function', 'name', 'tool'] as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                return $params[$key];
            }
        }

        throw JsonRpcException::invalidParams('Missing string param: function');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function objectParam(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        if (! is_array($value)) {
            throw JsonRpcException::invalidParams("Param must be an object: {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function scope(array $params): string
    {
        $scope = isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : 'project';
        if (! in_array($scope, ['project', 'global'], true)) {
            throw JsonRpcException::invalidParams('Scope must be project or global');
        }

        return $scope;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, scalar|null>
     */
    private function scalarMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_scalar($item) && $item !== null) {
                throw JsonRpcException::invalidParams("Value for {$key} must be scalar");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    private function stringMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $result[$key] = (string) $item;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    private function permissionMap(array $value): array
    {
        $permissions = $this->stringMap($value);
        foreach ($permissions as $operation => $permission) {
            if (! in_array($operation, ['read', 'write'], true) || ! in_array($permission, ['allow', 'ask', 'deny'], true)) {
                throw JsonRpcException::invalidParams("Invalid permission '{$operation}:{$permission}'. Use read/write with allow, ask, or deny.");
            }
        }

        return $permissions;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function mcpServers(array $params): array
    {
        $servers = $params['mcpServers'] ?? [];

        return is_array($servers) ? array_values(array_filter($servers, 'is_array')) : [];
    }

    private function extractPromptText(mixed $prompt): string
    {
        if (is_string($prompt)) {
            return $prompt;
        }
        if (! is_array($prompt)) {
            return '';
        }

        $parts = [];
        foreach ($prompt as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            } elseif (($block['type'] ?? '') === 'resource' && isset($block['resource']) && is_array($block['resource']) && isset($block['resource']['text'])) {
                $parts[] = (string) $block['resource']['text'];
            } elseif (($block['type'] ?? '') === 'resource_link' && isset($block['uri']) && is_string($block['uri']) && str_starts_with($block['uri'], 'file://')) {
                $path = parse_url($block['uri'], PHP_URL_PATH);
                if (is_string($path) && is_readable($path)) {
                    $parts[] = (string) file_get_contents($path);
                }
            }
        }

        return implode("\n", $parts);
    }

    private function normalizeModelId(string $modelId): string
    {
        $modelId = trim($modelId);

        return str_contains($modelId, '/') ? substr($modelId, (int) strrpos($modelId, '/') + 1) : $modelId;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function splitModelId(string $modelId): array
    {
        $modelId = trim($modelId);
        if (! str_contains($modelId, '/')) {
            return [null, $modelId];
        }

        $offset = (int) strrpos($modelId, '/');

        return [substr($modelId, 0, $offset), substr($modelId, $offset + 1)];
    }

    private function applyMode(AcpSessionState $state, string $modeId): void
    {
        $mode = AgentMode::from($modeId);
        $state->session->agentLoop->setMode($mode);
        $state->renderer->showCurrentMode($mode->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function modeState(AcpSessionState $state): array
    {
        $current = $state->session->agentLoop->getMode()->value;

        return [
            'currentModeId' => $current,
            'availableModes' => array_map(fn (AgentMode $mode) => [
                'id' => $mode->value,
                'name' => ucfirst($mode->value),
                'description' => match ($mode) {
                    AgentMode::Edit => 'Full coding access',
                    AgentMode::Plan => 'Read-only planning',
                    AgentMode::Ask => 'Read-only Q&A',
                },
            ], AgentMode::cases()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelState(AcpSessionState $state): array
    {
        $provider = $state->session->llm->getProvider();
        $current = $provider.'/'.$state->session->llm->getModel();
        $availableModels = [
            [
                'modelId' => $current,
                'name' => $current,
            ],
        ];

        if ($this->container->bound(ProviderCatalog::class)) {
            $definition = $this->container->make(ProviderCatalog::class)->provider($provider);
            if ($definition !== null && $definition->models !== []) {
                $availableModels = array_map(fn ($model) => [
                    'modelId' => $provider.'/'.$model->id,
                    'name' => $model->label(),
                ], $definition->models);
            }
        }

        return [
            'currentModelId' => $current,
            'availableModels' => $availableModels,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configOptions(AcpSessionState $state): array
    {
        $model = $this->modelState($state);
        $modes = $this->modeState($state);

        return [
            [
                'configId' => 'model',
                'name' => 'Model',
                'kind' => 'select',
                'value' => $model['currentModelId'],
                'options' => array_map(fn (array $item) => [
                    'value' => $item['modelId'],
                    'label' => $item['name'],
                ], $model['availableModels']),
            ],
            [
                'configId' => 'mode',
                'name' => 'Mode',
                'kind' => 'select',
                'value' => $modes['currentModeId'],
                'options' => array_map(fn (array $item) => [
                    'value' => $item['id'],
                    'label' => $item['name'],
                    'description' => $item['description'],
                ], $modes['availableModes']),
            ],
            [
                'configId' => 'permission_mode',
                'name' => 'Permission Mode',
                'kind' => 'select',
                'value' => $state->session->permissions->getPermissionMode()->value,
                'options' => array_map(fn (PermissionMode $mode) => [
                    'value' => $mode->value,
                    'label' => ucfirst($mode->value),
                ], PermissionMode::cases()),
            ],
        ];
    }
}
