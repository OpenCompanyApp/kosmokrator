<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\LLM\ProviderCatalog;
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
