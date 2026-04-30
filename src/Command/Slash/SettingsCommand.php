<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ModelSwitcherHistory;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderDefinition;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Web\Provider\WebFetchProviderManager;
use Kosmokrator\Web\Provider\WebSearchProviderManager;
use OpenCompany\IntegrationCore\Contracts\ConfigurableIntegration;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\PrismRelay\Registry\RelayRegistry;

/**
 * Opens the interactive settings workspace to configure providers, models, permissions,
 * API keys, and custom provider definitions at global or project scope.
 */
final class SettingsCommand implements SlashCommand
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return '/settings';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Open settings workspace';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $this->openWorkspace($ctx);

        return SlashCommandResult::continue();
    }

    /**
     * @param  array<string, mixed>  $viewOverrides
     */
    public function openWorkspace(SlashCommandContext $ctx, array $viewOverrides = []): bool
    {
        $catalog = $ctx->providers ?? $this->container->make(ProviderCatalog::class);
        $registry = $this->container->make(RelayRegistry::class);
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($ctx->sessionManager->getProject() ?? getcwd());

        $view = array_replace($this->buildSettingsView($ctx, $catalog, $settings), $viewOverrides);
        $result = $ctx->ui->showSettings($view);

        if ($result === [] || ! is_array($result)) {
            return false;
        }

        $this->applyWorkspaceResult($ctx, $catalog, $registry, $settings, $result);

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyWorkspaceResult(
        SlashCommandContext $ctx,
        ProviderCatalog $catalog,
        RelayRegistry $registry,
        SettingsManager $settings,
        array $result,
    ): void {
        $scope = (string) ($result['scope'] ?? 'project');
        $changes = is_array($result['changes'] ?? null) ? $result['changes'] : [];

        $customProvider = is_array($result['custom_provider'] ?? null) ? $result['custom_provider'] : null;
        $deleteCustomProvider = is_string($result['delete_custom_provider'] ?? null)
            ? trim((string) $result['delete_custom_provider'])
            : '';

        $setupProvider = trim((string) ($changes['provider.setup_provider'] ?? $ctx->llm->getProvider()));
        if ($setupProvider === '__custom__') {
            $setupProvider = trim((string) ($customProvider['id'] ?? ''));
        }

        if ($customProvider !== null) {
            $settings->saveCustomProvider(
                (string) $customProvider['id'],
                is_array($customProvider['definition'] ?? null) ? $customProvider['definition'] : [],
                $scope,
            );
            $ctx->ui->showNotice('Custom provider saved. Restart the session to reload the provider catalog.');
        }

        if ($deleteCustomProvider !== '') {
            $settings->deleteCustomProvider($deleteCustomProvider, $scope);
            $ctx->ui->showNotice("Custom provider {$deleteCustomProvider} removed. Restart the session to refresh the catalog.");
        }

        $targetProvider = (string) ($changes['agent.default_provider'] ?? $ctx->llm->getProvider());
        $targetModel = (string) ($changes['agent.default_model'] ?? $ctx->llm->getModel());
        if (! $catalog->supportsModel($targetProvider, $targetModel)) {
            $fallbackModel = $catalog->defaultModel($targetProvider) ?? ($catalog->modelIds($targetProvider)[0] ?? $targetModel);
            $changes['agent.default_model'] = $fallbackModel;
            $targetModel = $fallbackModel;
            $ctx->ui->showNotice("Model reset to {$fallbackModel} for {$targetProvider}.");
        }

        foreach ($changes as $id => $value) {
            $stringValue = is_scalar($value) || $value === null ? (string) $value : '';

            match ($id) {
                'agent.mode' => $this->applyMode($ctx, $stringValue, $scope),
                'tools.default_permission_mode' => $this->applyPermissionMode($ctx, $stringValue, $scope),
                'agent.temperature' => $this->applyTemperature($ctx, $stringValue, $scope),
                'agent.max_tokens' => $this->applyMaxTokens($ctx, $stringValue, $scope),
                'agent.reasoning_effort' => $this->applyReasoningEffort($ctx, $stringValue, $scope),
                'agent.default_provider' => $this->applyProvider($ctx, $catalog, $registry, $settings, $stringValue, $scope),
                'agent.default_model' => $this->applyModel($ctx, $settings, $targetProvider, $stringValue, $scope),
                'provider.secret.api_key' => $this->storeApiKey($ctx, $catalog, $setupProvider !== '' ? $setupProvider : $targetProvider, $stringValue),
                'gateway.telegram.secret.token' => $this->storeGatewayTelegramToken($ctx, $stringValue),
                'gateway.telegram.token_action' => $this->handleGatewayTelegramTokenAction($ctx, $stringValue),
                'provider.auth_action' => $this->handleAuthAction($ctx, $catalog, $setupProvider !== '' ? $setupProvider : $targetProvider, $stringValue),
                'provider.auth_status',
                'provider.setup_provider',
                'provider.setup_status',
                'provider.setup_auth_mode',
                'provider.setup_driver',
                'provider.setup_url',
                'custom_provider.id',
                'custom_provider.label',
                'custom_provider.driver',
                'custom_provider.url',
                'custom_provider.auth',
                'custom_provider.default_model',
                'custom_provider.model_id',
                'custom_provider.context',
                'custom_provider.max_output',
                'custom_provider.input_modalities',
                'custom_provider.output_modalities' => null,
                default => str_starts_with($id, 'integration.')
                    ? $this->applyIntegrationSetting($settings, $id, $stringValue, $scope)
                    : $ctx->sessionManager->setSetting($id, $stringValue, $scope),
            };
        }

        if (isset($changes['agent.default_provider']) || isset($changes['agent.default_model'])) {
            (new ModelSwitcherHistory($ctx->settings, $settings))->record($targetProvider, $targetModel);
            $modelCatalog = $ctx->models ?? $this->container->make(ModelCatalog::class);
            $ctx->ui->refreshRuntimeSelection($targetProvider, $targetModel, $modelCatalog->contextWindow($targetModel));
        }

        if (($changes['agent.default_provider'] ?? null) === 'codex' && ! isset($changes['provider.auth_action'])) {
            $flow = $this->container->make(CodexAuthFlow::class);
            if ($flow->current() === null) {
                $choice = $ctx->ui->askChoice('Codex needs ChatGPT authentication. Start login now?', [
                    ['label' => 'Browser login', 'detail' => 'Opens ChatGPT in your browser and waits for the callback on localhost.', 'recommended' => true],
                    ['label' => 'Device login', 'detail' => 'Shows a device code for headless or remote environments.', 'recommended' => false],
                    ['label' => 'Later', 'detail' => 'Keep Codex selected and authenticate later with `kosmo auth login codex`.', 'recommended' => false],
                ]);

                if ($choice === 'Browser login') {
                    $this->handleAuthAction($ctx, $catalog, 'codex', 'login_browser');
                } elseif ($choice === 'Device login') {
                    $this->handleAuthAction($ctx, $catalog, 'codex', 'login_device');
                }
            }
        }

        $updatedKeys = array_keys($changes);
        if ($customProvider !== null) {
            $updatedKeys[] = 'custom_provider';
        }
        if ($deleteCustomProvider !== '') {
            $updatedKeys[] = 'delete_custom_provider';
        }

        if ($updatedKeys !== []) {
            $ctx->ui->showNotice('Settings updated: '.implode(', ', $updatedKeys));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettingsView(SlashCommandContext $ctx, ProviderCatalog $catalog, SettingsManager $settings): array
    {
        $schema = $this->container->make(SettingsSchema::class);
        $currentProvider = $ctx->llm->getProvider();
        $customProvider = is_array($settings->customProviders()[$currentProvider] ?? null)
            ? $settings->customProviders()[$currentProvider]
            : [];
        $providerStatuses = $catalog->authStatuses();
        $setupProvider = $currentProvider;
        $integrationView = $this->buildIntegrationView($settings);

        $categories = [];
        foreach ($schema->categoryLabels() as $categoryId => $label) {
            $fields = [];
            foreach ($schema->definitionsForCategory($categoryId) as $definition) {
                $effective = $settings->resolve($definition->id);
                $value = $this->runtimeValue($ctx, $definition->id, $effective?->value);
                if ($definition->type === 'toggle') {
                    $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 'on' : 'off';
                }

                $fields[] = [
                    'id' => $definition->id,
                    'label' => $definition->label,
                    'value' => $value,
                    'source' => $effective?->source ?? 'default',
                    'effect' => $definition->effect,
                    'type' => $definition->type,
                    'options' => $definition->options,
                    'description' => $definition->description,
                ];
            }

            foreach ($fields as &$field) {
                if (($field['id'] ?? null) === 'web.search.default_provider'
                    && $this->container->bound(WebSearchProviderManager::class)) {
                    $field['options'] = $this->container->make(WebSearchProviderManager::class)->availableProviderIds();
                }

                if (($field['id'] ?? null) === 'web.fetch.default_provider'
                    && $this->container->bound(WebFetchProviderManager::class)) {
                    $field['options'] = $this->container->make(WebFetchProviderManager::class)->availableProviderIds();
                }
            }
            unset($field);

            if ($categoryId === 'models') {
                $providerId = (string) ($fields[0]['value'] ?? $currentProvider);
                $providerDef = $catalog->provider($providerId);
                if ($providerDef !== null && $providerDef->freeTextModel) {
                    $fields[] = [
                        'id' => 'provider.model_inventory',
                        'label' => 'Model entry',
                        'value' => 'Any model (free-text entry)',
                        'source' => 'runtime',
                        'effect' => 'next_session',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Type any model code directly in the model field (e.g. google/gemini-2.5-pro). This provider supports hundreds of models.',
                    ];
                } else {
                    $providerModelCount = count($catalog->modelIds($providerId));
                    $fields[] = [
                        'id' => 'provider.model_inventory',
                        'label' => 'Configured models',
                        'value' => $providerModelCount > 0 ? $providerModelCount.' models' : 'No models',
                        'source' => 'runtime',
                        'effect' => 'next_session',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Models currently available for the selected provider. Full list appears in the details panel below.',
                    ];
                }
            }

            if ($categoryId === 'provider_setup') {
                $setupProviderDefinition = $catalog->provider($setupProvider);
                $setupAuthMode = $catalog->authMode($setupProvider);
                [$credentialLabel, $credentialDescription] = $this->credentialFieldMeta($setupProvider, $setupAuthMode);
                $firstModelId = '';
                $firstModel = [];
                $models = is_array($customProvider['models'] ?? null) ? $customProvider['models'] : [];
                if ($models !== []) {
                    $firstModelId = (string) array_key_first($models);
                    $firstModel = is_array($models[$firstModelId] ?? null) ? $models[$firstModelId] : [];
                }

                $fields = array_merge($fields, [
                    [
                        'id' => 'provider.setup_provider',
                        'label' => 'Provider to configure',
                        'value' => $setupProvider,
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'dynamic_choice',
                        'options' => [],
                        'description' => 'Choose a built-in provider to configure, or switch to Custom Provider to write a YAML definition.',
                    ],
                    [
                        'id' => 'provider.setup_status',
                        'label' => 'Credential status',
                        'value' => $providerStatuses[$setupProvider] ?? 'Unknown',
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Current auth or credential status for the selected provider.',
                    ],
                    [
                        'id' => 'provider.setup_auth_mode',
                        'label' => 'Auth method',
                        'value' => $catalog->authMode($setupProvider),
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'How this provider authenticates: API key, OAuth, or none.',
                    ],
                    [
                        'id' => 'provider.setup_driver',
                        'label' => 'Driver',
                        'value' => (string) ($setupProviderDefinition?->driver ?? ''),
                        'source' => 'runtime',
                        'effect' => 'next_session',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Transport/adapter used to register this provider.',
                    ],
                    [
                        'id' => 'provider.setup_url',
                        'label' => 'Base URL',
                        'value' => (string) ($setupProviderDefinition?->url ?? ''),
                        'source' => 'runtime',
                        'effect' => 'next_session',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Endpoint currently configured for this provider.',
                    ],
                    [
                        'id' => 'provider.secret.api_key',
                        'label' => $credentialLabel,
                        'value' => $setupAuthMode === 'api_key' ? $catalog->maskedCredential($setupProvider) : '',
                        'source' => 'secret_store',
                        'effect' => 'applies_now',
                        'type' => $setupAuthMode === 'api_key' ? 'text' : 'readonly',
                        'options' => [],
                        'description' => $credentialDescription,
                    ],
                    [
                        'id' => 'provider.auth_action',
                        'label' => $setupAuthMode === 'oauth' ? 'Sign-in action' : 'Credential action',
                        'value' => '',
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'choice',
                        'options' => [],
                        'description' => $setupAuthMode === 'oauth'
                            ? 'Start sign-in, inspect login state, or sign out.'
                            : 'Set, inspect, or clear the saved credential for this provider.',
                    ],
                    [
                        'id' => 'custom_provider.id',
                        'label' => 'Custom provider ID',
                        'value' => $currentProvider !== '' && (($catalog->provider($currentProvider)?->source ?? '') === 'custom') ? $currentProvider : '',
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Create or edit a custom provider definition written to YAML.',
                    ],
                    [
                        'id' => 'custom_provider.label',
                        'label' => 'Custom label',
                        'value' => (string) ($customProvider['label'] ?? ''),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Human-readable provider label.',
                    ],
                    [
                        'id' => 'custom_provider.driver',
                        'label' => 'Custom driver',
                        'value' => (string) ($customProvider['driver'] ?? 'openai-compatible'),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'choice',
                        'options' => ['openai-compatible', 'openai', 'anthropic-compatible', 'deepseek', 'groq', 'mistral', 'ollama'],
                        'description' => 'Transport adapter used to register the provider with Prism.',
                    ],
                    [
                        'id' => 'custom_provider.url',
                        'label' => 'Custom base URL',
                        'value' => (string) ($customProvider['url'] ?? ''),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Provider base URL, for example an OpenAI-compatible endpoint.',
                    ],
                    [
                        'id' => 'custom_provider.auth',
                        'label' => 'Custom auth mode',
                        'value' => (string) ($customProvider['auth'] ?? 'api_key'),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'choice',
                        'options' => ['api_key', 'none'],
                        'description' => 'Authentication type used by the custom provider.',
                    ],
                    [
                        'id' => 'custom_provider.default_model',
                        'label' => 'Custom default model',
                        'value' => (string) ($customProvider['default_model'] ?? $firstModelId),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Default model ID for the custom provider.',
                    ],
                    [
                        'id' => 'custom_provider.model_id',
                        'label' => 'Custom model ID',
                        'value' => $firstModelId,
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Primary model to register in YAML.',
                    ],
                    [
                        'id' => 'custom_provider.context',
                        'label' => 'Custom model context',
                        'value' => (string) ($firstModel['context'] ?? ''),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'number',
                        'options' => [],
                        'description' => 'Context window for the custom model.',
                    ],
                    [
                        'id' => 'custom_provider.max_output',
                        'label' => 'Custom max output',
                        'value' => (string) ($firstModel['max_output'] ?? ''),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'number',
                        'options' => [],
                        'description' => 'Maximum output tokens for the custom model.',
                    ],
                    [
                        'id' => 'custom_provider.input_modalities',
                        'label' => 'Input modalities',
                        'value' => implode(', ', $firstModel['modalities']['input'] ?? $customProvider['modalities']['input'] ?? ['text']),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Comma-separated input modalities, e.g. text, image.',
                    ],
                    [
                        'id' => 'custom_provider.output_modalities',
                        'label' => 'Output modalities',
                        'value' => implode(', ', $firstModel['modalities']['output'] ?? $customProvider['modalities']['output'] ?? ['text']),
                        'source' => 'project',
                        'effect' => 'next_session',
                        'type' => 'text',
                        'options' => [],
                        'description' => 'Comma-separated output modalities, e.g. text, image, audio.',
                    ],
                ]);
            }

            if ($categoryId === 'auth') {
                $authMode = $catalog->authMode($currentProvider);
                $fields = array_merge($fields, [
                    [
                        'id' => 'provider.auth_status',
                        'label' => 'Auth status',
                        'value' => $providerStatuses[$currentProvider] ?? 'Unknown',
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'readonly',
                        'options' => [],
                        'description' => 'Current authentication state for the selected provider.',
                    ],
                    [
                        'id' => 'provider.secret.api_key',
                        'label' => 'API key',
                        'value' => $authMode === 'api_key' ? $catalog->maskedCredential($currentProvider) : '',
                        'source' => 'secret_store',
                        'effect' => 'applies_now',
                        'type' => $authMode === 'api_key' ? 'text' : 'readonly',
                        'options' => [],
                        'description' => 'API key is stored separately from YAML config.',
                    ],
                    [
                        'id' => 'provider.auth_action',
                        'label' => 'Auth action',
                        'value' => '',
                        'source' => 'runtime',
                        'effect' => 'applies_now',
                        'type' => 'choice',
                        'options' => $this->authActionOptions($authMode),
                        'description' => 'Run a provider-specific auth workflow.',
                    ],
                ]);
            }

            if ($categoryId === 'integrations') {
                $fields = array_merge($fields, $integrationView['fields']);
            }

            if ($categoryId === 'gateway') {
                $fields = array_merge($fields, $this->gatewayFields($ctx));
            }

            $categories[] = [
                'id' => $categoryId,
                'label' => $label,
                'fields' => $fields,
            ];
        }

        return [
            'title' => 'Settings',
            'scope' => $ctx->sessionManager->getProject() !== null ? 'project' : 'global',
            'categories' => $categories,
            'provider_options' => $catalog->providerOptions(),
            'setup_provider_options' => $this->setupProviderOptions($catalog),
            'model_options_by_provider' => $catalog->modelOptionsByProvider(),
            'free_text_model_providers' => array_map(
                static fn (ProviderDefinition $p): string => $p->id,
                array_filter($catalog->providers(), static fn (ProviderDefinition $p): bool => $p->freeTextModel),
            ),
            'models_provider_options' => $this->configuredProviderOptions($catalog, $currentProvider),
            'models_model_options_by_provider' => $this->configuredModelOptionsByProvider($catalog, $currentProvider),
            'provider_statuses' => $providerStatuses,
            'provider_api_key_display' => $this->providerApiKeyDisplay($catalog),
            'providers_by_id' => $this->providersById($catalog),
            'custom_provider_definitions' => $settings->customProviders(),
            'auth_action_options_by_provider' => $this->authActionOptionsByProvider($catalog),
            'integrations_by_id' => $integrationView['providers'],
            'integration_empty_state' => $integrationView['empty_state'],
        ];
    }

    /** Applies a mode change to both the agent loop runtime and persisted settings. */
    private function applyMode(SlashCommandContext $ctx, string $value, string $scope): void
    {
        $mode = AgentMode::from($value);
        $ctx->agentLoop->setMode($mode);
        $ctx->ui->showMode($mode->label(), $mode->color());
        $ctx->sessionManager->setSetting('agent.mode', $value, $scope);
    }

    /** Applies a permission mode change to both the runtime permission manager and persisted settings. */
    private function applyPermissionMode(SlashCommandContext $ctx, string $value, string $scope): void
    {
        $mode = PermissionMode::from($value);
        $ctx->permissions->setPermissionMode($mode);
        $ctx->ui->setPermissionMode($mode->statusLabel(), $mode->color());
        $ctx->sessionManager->setSetting('tools.default_permission_mode', $value, $scope);
    }

    /** Updates the LLM temperature at runtime and persists the new value. */
    private function applyTemperature(SlashCommandContext $ctx, string $value, string $scope): void
    {
        $ctx->llm->setTemperature((float) $value);
        $ctx->sessionManager->setSetting('agent.temperature', $value, $scope);
    }

    /** Updates the max output tokens limit at runtime and persists the new value. */
    private function applyMaxTokens(SlashCommandContext $ctx, string $value, string $scope): void
    {
        $tokens = $value !== '' ? (int) $value : null;
        $ctx->llm->setMaxTokens($tokens);
        $ctx->sessionManager->setSetting('agent.max_tokens', $value, $scope);
    }

    /** Updates the reasoning effort level at runtime and persists the new value. */
    private function applyReasoningEffort(SlashCommandContext $ctx, string $value, string $scope): void
    {
        $ctx->llm->setReasoningEffort($value);
        $ctx->sessionManager->setSetting('agent.reasoning_effort', $value, $scope);
    }

    /**
     * Switches the active LLM provider at runtime if possible, otherwise flags
     * a restart requirement. Updates the base URL and API key on the inner client.
     */
    private function applyProvider(
        SlashCommandContext $ctx,
        ProviderCatalog $catalog,
        RelayRegistry $registry,
        SettingsManager $settings,
        string $provider,
        string $scope,
    ): void {
        $settings->set('agent.default_provider', $provider, $scope);

        if ($this->requiresRestart($ctx->llm, $registry, $provider)) {
            $ctx->ui->showNotice("Provider saved as {$provider}. Restart session to switch runtime.");

            return;
        }

        $ctx->llm->setProvider($provider);
        $inner = self::innerClient($ctx->llm);

        if ($provider !== 'codex' && method_exists($inner, 'setBaseUrl')) {
            $inner->setBaseUrl(rtrim($registry->url($provider), '/'));
        }

        if (method_exists($inner, 'setApiKey')) {
            $inner->setApiKey($provider === 'codex' ? '' : $catalog->apiKey($provider));
        }
    }

    /**
     * Persists the selected model and updates it at runtime if the provider
     * doesn't require a restart.
     */
    private function applyModel(
        SlashCommandContext $ctx,
        SettingsManager $settings,
        string $provider,
        string $model,
        string $scope,
    ): void {
        $settings->set('agent.default_model', $model, $scope);
        $settings->setProviderLastModel($provider, $model, $scope);

        if (! $this->requiresRestart($ctx->llm, $this->container->make(RelayRegistry::class), $provider)) {
            $ctx->llm->setModel($model);
        }
    }

    /**
     * Stores an API key in the global secret store and applies it to the inner client if hot-reloadable.
     */
    private function storeApiKey(SlashCommandContext $ctx, ProviderCatalog $catalog, string $provider, string $value): void
    {
        if (
            $value === ''
            || str_starts_with($value, '(')
            || $provider === ''
            || $provider === 'codex'
            || $value === $catalog->maskedCredential($provider)
        ) {
            return;
        }

        $ctx->settings->set('global', "provider.{$provider}.api_key", $value);
        $inner = self::innerClient($ctx->llm);

        if (! $this->requiresRestart($ctx->llm, $this->container->make(RelayRegistry::class), $provider) && method_exists($inner, 'setApiKey')) {
            $inner->setApiKey($value);
        }
    }

    private function storeGatewayTelegramToken(SlashCommandContext $ctx, string $value): void
    {
        if ($value === '' || str_starts_with($value, '(')) {
            return;
        }

        $ctx->settings->set('global', 'gateway.telegram.token', $value);
    }

    private function handleGatewayTelegramTokenAction(SlashCommandContext $ctx, string $action): void
    {
        if ($action === '') {
            return;
        }

        if ($action === 'clear_token') {
            $ctx->settings->delete('global', 'gateway.telegram.token');
            $ctx->ui->showNotice('Cleared Telegram gateway token.');

            return;
        }

        if ($action === 'edit_token') {
            $token = trim($ctx->ui->askUser('Enter Telegram bot token:'));
            if ($token !== '') {
                $ctx->settings->set('global', 'gateway.telegram.token', $token);
                $ctx->ui->showNotice('Stored Telegram gateway token.');
            }

            return;
        }

        if ($action === 'status') {
            $ctx->ui->showNotice($this->gatewayTelegramTokenStatus($ctx));
        }
    }

    /**
     * Dispatches provider-specific auth workflows: API key management, OAuth browser/device
     * login flows, and credential status inspection.
     */
    private function handleAuthAction(SlashCommandContext $ctx, ProviderCatalog $catalog, string $provider, string $action): void
    {
        if ($action === '') {
            return;
        }

        $authMode = $catalog->authMode($provider);

        if ($authMode === 'none') {
            $ctx->ui->showNotice('No authentication is required for this provider.');

            return;
        }

        if ($authMode === 'api_key') {
            if ($action === 'status') {
                $ctx->ui->showNotice($catalog->authStatus($provider));

                return;
            }

            if ($action === 'clear_key') {
                $ctx->settings->delete('global', "provider.{$provider}.api_key");
                $inner = self::innerClient($ctx->llm);
                if (! $this->requiresRestart($ctx->llm, $this->container->make(RelayRegistry::class), $provider) && method_exists($inner, 'setApiKey')) {
                    $inner->setApiKey('');
                }
                $ctx->ui->showNotice("Cleared API key for {$provider}.");

                return;
            }

            if ($action === 'edit_key') {
                $key = trim($ctx->ui->askUser("Enter API key for {$provider}:"));
                if ($key !== '') {
                    $this->storeApiKey($ctx, $catalog, $provider, $key);
                    $ctx->ui->showNotice("Stored API key for {$provider}.");
                }
            }

            return;
        }

        $flow = $this->container->make(CodexAuthFlow::class);

        try {
            if ($action === 'status') {
                $ctx->ui->showNotice($catalog->authStatus('codex'));

                return;
            }

            if ($action === 'logout') {
                $flow->logout();
                $ctx->ui->showNotice('Removed Codex authentication.');

                return;
            }

            $token = match ($action) {
                'login_device' => $flow->deviceLogin(fn (string $message) => $ctx->ui->showNotice($message)),
                default => $flow->browserLogin(fn (string $message) => $ctx->ui->showNotice($message)),
            };

            $ctx->ui->showNotice('Codex authenticated as '.($token->email ?? 'your ChatGPT account').'.');
        } catch (\Throwable $e) {
            $ctx->ui->showError($e->getMessage());
        }
    }

    private function requiresRestart(LlmClientInterface $llm, RelayRegistry $registry, string $provider): bool
    {
        // Restart is needed when the client uses async streaming but the provider doesn't support it

        $inner = self::innerClient($llm);

        return $inner instanceof AsyncLlmClient && ! $registry->supportsAsync($provider);
    }

    /** Unwraps the inner LLM client from the RetryableLlmClient decorator if present. */
    private static function innerClient(LlmClientInterface $llm): LlmClientInterface
    {
        return $llm instanceof RetryableLlmClient ? $llm->inner() : $llm;
    }

    /**
     * Builds a lookup map of provider metadata keyed by provider ID.
     *
     * @return array<string, mixed>
     */
    private function providersById(ProviderCatalog $catalog): array
    {
        $map = [];
        foreach ($catalog->providers() as $provider) {
            $map[$provider->id] = [
                'label' => $provider->label,
                'description' => $provider->description,
                'source' => $provider->source,
                'driver' => $provider->driver,
                'url' => $provider->url,
                'auth_mode' => $provider->authMode,
                'auth_status' => $catalog->authStatus($provider->id),
                'input_modalities' => $provider->inputModalities,
                'output_modalities' => $provider->outputModalities,
                'free_text_model' => $provider->freeTextModel,
            ];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function providerApiKeyDisplay(ProviderCatalog $catalog): array
    {
        $values = [];

        foreach ($catalog->providers() as $provider) {
            $values[$provider->id] = $catalog->authMode($provider->id) === 'api_key'
                ? $catalog->maskedCredential($provider->id)
                : '';
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gatewayFields(SlashCommandContext $ctx): array
    {
        $value = $ctx->settings->get('global', 'gateway.telegram.token');
        $masked = $value !== null && $value !== '' ? $this->maskSecret($value) : '';

        return [
            [
                'id' => 'gateway.telegram.secret.token',
                'label' => 'Telegram bot token',
                'value' => $masked,
                'source' => 'secret_store',
                'effect' => 'applies_now',
                'type' => 'text',
                'options' => [],
                'description' => 'Bot token stored separately from YAML config.',
            ],
            [
                'id' => 'gateway.telegram.token_action',
                'label' => 'Token action',
                'value' => '',
                'source' => 'runtime',
                'effect' => 'applies_now',
                'type' => 'choice',
                'options' => ['status', 'edit_token', 'clear_token'],
                'description' => 'Inspect, replace, or clear the stored Telegram bot token.',
            ],
        ];
    }

    private function gatewayTelegramTokenStatus(SlashCommandContext $ctx): string
    {
        $value = $ctx->settings->get('global', 'gateway.telegram.token');

        return ($value !== null && $value !== '')
            ? 'Telegram bot token is configured.'
            : 'Telegram bot token is not configured.';
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function authActionOptions(string $authMode): array
    {
        return match ($authMode) {
            'oauth' => [
                ['value' => '', 'label' => 'Choose action', 'description' => 'No action.'],
                ['value' => 'status', 'label' => 'Check login status', 'description' => 'Show the current OAuth login state.'],
                ['value' => 'login_browser', 'label' => 'Sign in in browser', 'description' => 'Open the browser-based login flow.'],
                ['value' => 'login_device', 'label' => 'Sign in with device code', 'description' => 'Use a device-code flow for remote or headless environments.'],
                ['value' => 'logout', 'label' => 'Sign out / switch account', 'description' => 'Remove the saved OAuth session so you can connect a different account.'],
            ],
            'none' => [
                ['value' => '', 'label' => 'No action needed', 'description' => 'This provider does not require credentials.'],
                ['value' => 'status', 'label' => 'Check provider status', 'description' => 'Show the current provider status.'],
            ],
            default => [
                ['value' => '', 'label' => 'Choose action', 'description' => 'No action.'],
                ['value' => 'status', 'label' => 'Check saved key', 'description' => 'Show whether a key is already configured.'],
                ['value' => 'edit_key', 'label' => 'Set or replace key', 'description' => 'Enter a new key for this provider.'],
                ['value' => 'clear_key', 'label' => 'Clear saved key', 'description' => 'Remove the currently saved key.'],
            ],
        };
    }

    /**
     * @return array<string, array<int, array{value: string, label: string, description: string}>>
     */
    private function authActionOptionsByProvider(ProviderCatalog $catalog): array
    {
        $options = [];
        foreach ($catalog->providers() as $provider) {
            $options[$provider->id] = $this->authActionOptions($provider->authMode);
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentialFieldMeta(string $provider, string $authMode): array
    {
        if ($authMode !== 'api_key') {
            return ['Credential', 'Credential is managed separately from YAML config.'];
        }

        return match ($provider) {
            'mimo' => ['Token plan key', 'Enter the Xiaomi MiMo token-plan key (`tp-...`). It is stored separately from YAML config.'],
            default => ['API key', 'Enter the API key for this provider. It is stored separately from YAML config.'],
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function setupProviderOptions(ProviderCatalog $catalog): array
    {
        $options = $catalog->providerOptions();
        $options[] = [
            'value' => '__custom__',
            'label' => 'Custom Provider',
            'description' => 'Define a provider in YAML with a custom driver, URL, models, and modalities.',
        ];

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function configuredProviderOptions(ProviderCatalog $catalog, string $currentProvider): array
    {
        return array_values(array_filter(
            $catalog->providerOptions(),
            fn (array $option): bool => $this->providerIsConfigured($catalog, (string) ($option['value'] ?? ''), $currentProvider),
        ));
    }

    /**
     * @return array<string, list<array{value: string, label: string, description: string}>>
     */
    private function configuredModelOptionsByProvider(ProviderCatalog $catalog, string $currentProvider): array
    {
        $models = $catalog->modelOptionsByProvider();
        $filtered = [];

        foreach (array_keys($models) as $provider) {
            if (! $this->providerIsConfigured($catalog, $provider, $currentProvider)) {
                continue;
            }

            $filtered[$provider] = $models[$provider];
        }

        return $filtered;
    }

    private function providerIsConfigured(ProviderCatalog $catalog, string $provider, string $currentProvider): bool
    {
        if ($provider === '' || $provider === $currentProvider) {
            return $provider !== '';
        }

        return match ($catalog->authMode($provider)) {
            'none' => true,
            'oauth' => ! str_starts_with($catalog->authStatus($provider), 'Not authenticated')
                && ! str_starts_with($catalog->authStatus($provider), 'Expired'),
            default => trim($catalog->apiKey($provider)) !== '',
        };
    }

    /** Resolves the current runtime value for a setting, falling back to persisted config. */
    private function runtimeValue(SlashCommandContext $ctx, string $id, mixed $fallback): string
    {
        return match ($id) {
            'agent.mode' => $ctx->agentLoop->getMode()->value,
            'tools.default_permission_mode' => $ctx->permissions->getPermissionMode()->value,
            'agent.default_provider' => $ctx->llm->getProvider(),
            'agent.default_model' => $ctx->llm->getModel(),
            'agent.temperature' => (string) ($ctx->llm->getTemperature() ?? 0.0),
            'agent.max_tokens' => (string) ($ctx->llm->getMaxTokens() ?? ''),
            'agent.reasoning_effort' => $ctx->llm->getReasoningEffort(),
            'context.compact_threshold' => (string) ($ctx->agentLoop->getCompactor()?->getCompactThresholdPercent() ?? $fallback ?? 60),
            'context.prune_protect' => (string) ($ctx->agentLoop->getPruner()?->getProtectTokens() ?? $fallback ?? 40000),
            'context.prune_min_savings' => (string) ($ctx->agentLoop->getPruner()?->getMinSavings() ?? $fallback ?? 20000),
            default => $this->stringifySettingValue($fallback),
        };
    }

    private function stringifySettingValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_array($value)) {
            $items = array_values(array_filter(array_map(static function (mixed $item): string {
                if (is_scalar($item) || $item === null) {
                    return trim((string) $item);
                }

                return '';
            }, $value), static fn (string $item): bool => $item !== ''));

            return implode(', ', $items);
        }

        return (string) $value;
    }

    /**
     * Build dynamic fields for the Integrations settings category.
     *
     * Shows per-integration enabled toggle, read permission, and write permission.
     * Only integrations that are CLI-compatible (no OAuth) are shown.
     *
     * @return array<int, array{id: string, label: string, value: string, source: string, effect: string, type: string, options: list<string>, description: string}>
     */
    private function buildIntegrationFields(SettingsManager $settings, IntegrationManager $manager, YamlCredentialResolver $resolver): array
    {
        $fields = [];
        $providers = $manager->getLocallyRunnableProviders();
        $enabled = [];
        $available = [];

        foreach ($providers as $name => $provider) {
            $integration = $this->buildIntegrationProviderView($settings, $resolver, $name, $provider, true);
            if ($integration['enabled']) {
                $enabled[$name] = $integration;
            } else {
                $available[$name] = $integration;
            }
        }

        uasort($enabled, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        uasort($available, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        if ($enabled !== []) {
            $fields[] = $this->integrationSectionField(
                'integration._section.enabled',
                'Enabled Integrations',
                count($enabled).' active',
                'Integrations that are currently enabled for agent use.',
            );
            foreach ($enabled as $name => $integration) {
                $fields = array_merge($fields, $this->integrationFieldsForProvider($settings, $integration));
            }
        }

        if ($available !== []) {
            $fields[] = $this->integrationSectionField(
                'integration._section.available',
                'Available Integrations',
                count($available).' available',
                'Installed CLI-compatible integrations that are available to configure and enable.',
            );
            foreach ($available as $name => $integration) {
                $fields = array_merge($fields, $this->integrationFieldsForProvider($settings, $integration));
            }
        }

        if ($fields === []) {
            $fields[] = $this->integrationSectionField(
                'integration._section.empty',
                'Available Integrations',
                '0 available',
                'No CLI-compatible integrations are currently available.',
            );
        }

        $fields[] = $this->integrationSectionField(
            'integration._section.actions',
            'Bulk Actions',
            '',
            'Apply permission defaults across configured integrations.',
        );

        // Bulk operations
        $fields[] = [
            'id' => 'integration._bulk_allow',
            'label' => '  Allow all integrations (read + write)',
            'value' => '',
            'source' => 'runtime',
            'effect' => 'applies_now',
            'type' => 'choice',
            'options' => ['', 'yes'],
            'description' => 'Set all integration read and write permissions to "allow".',
        ];
        $fields[] = [
            'id' => 'integration._bulk_ask_writes',
            'label' => '  Require approval for all writes',
            'value' => '',
            'source' => 'runtime',
            'effect' => 'applies_now',
            'type' => 'choice',
            'options' => ['', 'yes'],
            'description' => 'Set all integration write permissions to "ask". Read stays as-is.',
        ];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $integration
     * @return array<int, array{id: string, label: string, value: string, source: string, effect: string, type: string, options: list<string>, description: string}>
     */
    private function integrationFieldsForProvider(SettingsManager $settings, array $integration): array
    {
        $name = (string) $integration['id'];
        $fields = [];
        $description = $integration['description'];
        $isConfigured = $integration['configured'];
        $summary = [];
        $summary[] = $isConfigured ? 'Configured' : 'Not configured';
        $summary[] = $integration['enabled'] ? 'Enabled' : 'Disabled';

        if ($integration['credential_fields'] !== []) {
            $summary[] = count($integration['credential_fields']).' credential fields';
        }

        $fields[] = [
            'id' => "integration.{$name}._summary",
            'label' => (string) ($integration['name'] ?? $integration['label']),
            'value' => implode(' · ', $summary),
            'source' => 'runtime',
            'effect' => 'applies_now',
            'type' => 'readonly',
            'options' => [],
            'description' => $description,
        ];

        // Enabled toggle
        $enabled = $settings->getRaw("kosmo.integrations.{$name}.enabled");
        $fields[] = [
            'id' => "integration.{$name}.enabled",
            'label' => '  Enabled',
            'value' => ($enabled === true || $enabled === 'on') ? 'on' : 'off',
            'source' => $settings->rawSource("kosmo.integrations.{$name}.enabled") ?? 'default',
            'effect' => 'next_session',
            'type' => 'toggle',
            'options' => ['on', 'off'],
            'description' => "Enable or disable the {$name} integration.",
        ];

        $readPerm = $settings->getRaw("kosmo.integrations.{$name}.permissions.read") ?? 'allow';
        $fields[] = [
            'id' => "integration.{$name}.permissions.read",
            'label' => '  Read access',
            'value' => $readPerm,
            'source' => $settings->rawSource("kosmo.integrations.{$name}.permissions.read") ?? 'default',
            'effect' => 'applies_now',
            'type' => 'choice',
            'options' => ['allow', 'ask', 'deny'],
            'description' => "Read access for {$name}. allow = auto-approve, ask = require approval, deny = blocked.",
        ];

        $writePerm = $settings->getRaw("kosmo.integrations.{$name}.permissions.write") ?? 'allow';
        $fields[] = [
            'id' => "integration.{$name}.permissions.write",
            'label' => '  Write access',
            'value' => $writePerm,
            'source' => $settings->rawSource("kosmo.integrations.{$name}.permissions.write") ?? 'default',
            'effect' => 'applies_now',
            'type' => 'choice',
            'options' => ['allow', 'ask', 'deny'],
            'description' => "Write access for {$name}. allow = auto-approve, ask = require approval, deny = blocked.",
        ];

        $accountValue = $integration['accounts'] === [] ? 'default account only' : 'default + '.implode(', ', $integration['accounts']);
        $fields[] = [
            'id' => "integration.{$name}._accounts",
            'label' => '  Accounts',
            'value' => $accountValue,
            'source' => 'secret_store',
            'effect' => 'applies_now',
            'type' => 'readonly',
            'options' => [],
            'description' => 'Integration credentials are stored globally. Additional aliases are listed here; the settings workspace currently edits the default account.',
        ];

        foreach ($integration['credential_fields'] as $credential) {
            $fields[] = [
                'id' => "integration.{$name}.credential.{$credential['key']}",
                'label' => '  '.$credential['label'],
                'value' => $credential['display_value'],
                'source' => 'secret_store',
                'effect' => 'applies_now',
                'type' => $credential['input_type'],
                'options' => $credential['options'],
                'description' => $credential['description'],
            ];
        }

        if ($integration['credential_fields'] !== [] || $isConfigured) {
            $fields[] = [
                'id' => "integration.{$name}.credential_action",
                'label' => '  Credential action',
                'value' => '',
                'source' => 'runtime',
                'effect' => 'applies_now',
                'type' => 'choice',
                'options' => ['', 'clear_saved'],
                'description' => 'Clear all saved credentials for the default account of this integration.',
            ];
        }

        return $fields;
    }

    /**
     * @return array{id: string, label: string, value: string, source: string, effect: string, type: string, options: list<string>, description: string}
     */
    private function integrationSectionField(string $id, string $label, string $value, string $description): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'source' => 'runtime',
            'effect' => 'applies_now',
            'type' => 'readonly',
            'options' => [],
            'description' => $description,
        ];
    }

    private function applyIntegrationSetting(SettingsManager $settings, string $id, string $value, string $scope): void
    {
        if (preg_match('/^integration\.([^.]+)\._/', $id)) {
            return;
        }

        // Handle bulk operations
        if ($id === 'integration._bulk_allow' && $value === 'yes') {
            if ($this->container->bound(IntegrationManager::class)) {
                $manager = $this->container->make(IntegrationManager::class);
                $manager->setAllPermissions('allow', null, $scope);
            }

            return;
        }

        if ($id === 'integration._bulk_ask_writes' && $value === 'yes') {
            if ($this->container->bound(IntegrationManager::class)) {
                $manager = $this->container->make(IntegrationManager::class);
                $manager->setAllPermissions('ask', 'write', $scope);
            }

            return;
        }

        if (preg_match('/^integration\.([^.]+)\.credential_action$/', $id, $m)) {
            if ($value === 'clear_saved') {
                $this->container->make(YamlCredentialResolver::class)->removeIntegration($m[1]);
            }

            return;
        }

        if (preg_match('/^integration\.([^.]+)\.credential\.([^.]+)$/', $id, $m)) {
            if ($value === '') {
                return;
            }

            $integration = $m[1];
            $key = $m[2];
            $resolver = $this->container->make(YamlCredentialResolver::class);
            $current = (string) $resolver->get($integration, $key, '');
            $field = $this->integrationConfigFieldMap($integration)[$key] ?? null;

            if (($field['type'] ?? 'text') === 'secret' && $current !== '' && $value === $this->maskSecret($current)) {
                return;
            }

            $resolver->registerAccount($integration);
            $resolver->set($integration, $key, $value);

            return;
        }

        // Parse integration.{name}.enabled → set in YAML
        if (preg_match('/^integration\.([^.]+)\.enabled$/', $id, $m)) {
            $settings->setRaw("kosmo.integrations.{$m[1]}.enabled", $value === 'on', $scope);

            return;
        }

        // Parse integration.{name}.permissions.{operation} → set in YAML
        if (preg_match('/^integration\.([^.]+)\.permissions\.(read|write)$/', $id, $m)) {
            if (in_array($value, ['allow', 'ask', 'deny'], true)) {
                $settings->setRaw("kosmo.integrations.{$m[1]}.permissions.{$m[2]}", $value, $scope);
            }

            return;
        }

        // Fallback: store as-is
        $settings->setRaw($id, $value, $scope);
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, providers: array<string, array<string, mixed>>, empty_state: array<string, mixed>|null}
     */
    private function buildIntegrationView(SettingsManager $settings): array
    {
        if (! $this->container->bound(IntegrationManager::class)) {
            return [
                'fields' => [[
                    'id' => 'integrations._unavailable',
                    'label' => 'Integration system unavailable',
                    'value' => 'No integration runtime is bound.',
                    'source' => 'runtime',
                    'effect' => 'applies_now',
                    'type' => 'readonly',
                    'options' => [],
                    'description' => 'The integration service provider is not registered in this runtime.',
                ]],
                'providers' => [],
                'empty_state' => [
                    'title' => 'Integrations unavailable',
                    'message' => 'The integration runtime is not available in this session.',
                    'details' => ['Register the integration service provider before opening settings.'],
                ],
            ];
        }

        $manager = $this->container->make(IntegrationManager::class);
        $resolver = $this->container->make(YamlCredentialResolver::class);
        $allProviders = $manager->getAllProviders();
        $runnableProviders = $manager->getLocallyRunnableProviders();
        $providerViews = [];

        foreach ($allProviders as $name => $provider) {
            $providerViews[$name] = $this->buildIntegrationProviderView(
                $settings,
                $resolver,
                $name,
                $provider,
                isset($runnableProviders[$name]),
            );
        }

        if ($allProviders === []) {
            return [
                'fields' => [[
                    'id' => 'integrations._none',
                    'label' => 'No integrations installed',
                    'value' => '0 installed packages',
                    'source' => 'runtime',
                    'effect' => 'applies_now',
                    'type' => 'readonly',
                    'options' => [],
                    'description' => 'Install OpenCompany integration packages to enable integrations in the CLI.',
                ]],
                'providers' => [],
                'empty_state' => [
                    'title' => 'No integrations installed',
                    'message' => 'No OpenCompany integration packages were found in this install.',
                    'details' => [
                        'Install `opencompanyapp/integration-*` packages, or the current `opencompanyapp/ai-tool-*` packages, and reopen settings to manage them here.',
                    ],
                ],
            ];
        }

        if ($runnableProviders === []) {
            $labels = array_map(
                static fn (array $provider): string => $provider['label'],
                array_values($providerViews),
            );

            return [
                'fields' => [[
                    'id' => 'integrations._oauth_only',
                    'label' => 'No CLI-compatible integrations',
                    'value' => count($allProviders).' installed packages',
                    'source' => 'runtime',
                    'effect' => 'applies_now',
                    'type' => 'readonly',
                    'options' => [],
                    'description' => 'Installed integrations currently require browser/OAuth flows or a non-CLI host: '.implode(', ', $labels).'.',
                ]],
                'providers' => $providerViews,
                'empty_state' => [
                    'title' => 'No CLI-compatible integrations',
                    'message' => 'Installed integrations are not locally runnable in the terminal.',
                    'details' => ['Installed: '.implode(', ', $labels)],
                ],
            ];
        }

        return [
            'fields' => $this->buildIntegrationFields($settings, $manager, $resolver),
            'providers' => $providerViews,
            'empty_state' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIntegrationProviderView(
        SettingsManager $settings,
        YamlCredentialResolver $resolver,
        string $name,
        ToolProvider $provider,
        bool $locallyRunnable,
    ): array {
        $meta = $provider->appMeta();
        $integrationMeta = $provider instanceof ConfigurableIntegration ? $provider->integrationMeta() : [];
        $fields = $this->integrationConfigFields($provider);
        $accounts = $resolver->getAccounts($name);
        $credentialViews = [];

        foreach ($fields as $field) {
            $value = $resolver->get($name, $field['key'], $field['default'] ?? null);
            $stringValue = is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
            $credentialViews[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => (bool) ($field['required'] ?? false),
                'input_type' => in_array($field['type'], ['choice', 'toggle', 'dynamic_choice'], true) ? $field['type'] : (($field['type'] ?? 'text') === 'select' ? 'choice' : 'text'),
                'options' => $field['options'] ?? [],
                'description' => $this->integrationFieldDescription($field),
                'display_value' => ($field['type'] ?? 'text') === 'secret'
                    ? ($stringValue !== '' ? $this->maskSecret($stringValue) : '')
                    : $stringValue,
                'configured' => $stringValue !== '',
            ];
        }

        $requiredCredentialViews = array_values(array_filter(
            $credentialViews,
            static fn (array $field): bool => (bool) ($field['required'] ?? false),
        ));
        $configured = $requiredCredentialViews === [] || array_all(
            $requiredCredentialViews,
            static fn (array $field): bool => $field['configured'] === true,
        );

        $enabled = $settings->getRaw("kosmo.integrations.{$name}.enabled");
        $rawLabel = trim((string) ($meta['label'] ?? ''));
        $displayName = $this->integrationDisplayName($name, $provider, $meta, $integrationMeta);
        $label = $this->integrationDisplayLabel($rawLabel, $displayName);

        return [
            'id' => $name,
            'name' => $displayName,
            'label' => $label,
            'description' => (string) ($integrationMeta['description'] ?? $meta['description'] ?? $displayName),
            'icon' => (string) ($meta['icon'] ?? ''),
            'logo' => (string) ($integrationMeta['logo'] ?? $meta['logo'] ?? ''),
            'category' => (string) ($integrationMeta['category'] ?? ''),
            'badge' => (string) ($integrationMeta['badge'] ?? ''),
            'docs_url' => (string) ($integrationMeta['docs_url'] ?? ''),
            'locally_runnable' => $locallyRunnable,
            'configured' => $configured,
            'enabled' => $enabled === true || $enabled === 'on',
            'accounts' => $accounts,
            'credential_fields' => $credentialViews,
            'read_permission' => (string) ($settings->getRaw("kosmo.integrations.{$name}.permissions.read") ?? 'allow'),
            'write_permission' => (string) ($settings->getRaw("kosmo.integrations.{$name}.permissions.write") ?? 'allow'),
            'tool_count' => count($provider->tools()),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $integrationMeta
     */
    private function integrationDisplayName(string $appName, ToolProvider $provider, array $meta, array $integrationMeta): string
    {
        $integrationName = trim((string) ($integrationMeta['name'] ?? ''));
        if ($integrationName !== '') {
            return $integrationName;
        }

        $label = trim((string) ($meta['label'] ?? ''));
        if ($this->isHumanFacingIntegrationLabel($label)) {
            return $label;
        }

        $className = $provider::class;
        $shortName = strrpos($className, '\\') !== false
            ? substr($className, (int) strrpos($className, '\\') + 1)
            : $className;
        $shortName = preg_replace('/ToolProvider$/', '', $shortName) ?? $shortName;
        if ($shortName !== '' && $shortName !== 'class@anonymous') {
            return $this->humanizeIntegrationIdentifier($shortName);
        }

        return $this->humanizeIntegrationIdentifier($appName);
    }

    private function integrationDisplayLabel(string $rawLabel, string $displayName): string
    {
        if ($this->isHumanFacingIntegrationLabel($rawLabel)) {
            return $rawLabel;
        }

        return $displayName;
    }

    private function isHumanFacingIntegrationLabel(string $label): bool
    {
        if ($label === '') {
            return false;
        }

        return ! str_contains($label, ',');
    }

    private function humanizeIntegrationIdentifier(string $identifier): string
    {
        $normalized = str_replace(['-', '_'], ' ', $identifier);
        $normalized = preg_replace('/(?<!^)([A-Z])/', ' $1', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === strtolower($normalized)) {
            return ucwords($normalized);
        }

        return $normalized;
    }

    /**
     * @return list<array{key: string, type: string, label: string, required?: bool, default?: mixed, placeholder?: string, options?: list<string>, hint?: string}>
     */
    private function integrationConfigFields(ToolProvider $provider): array
    {
        if ($provider instanceof ConfigurableIntegration) {
            $fields = [];
            foreach ($provider->configSchema() as $field) {
                $type = (string) ($field['type'] ?? 'text');
                if ($type === 'oauth_connect') {
                    continue;
                }

                $options = [];
                $rawOptions = $field['options'] ?? [];
                if ($type === 'select' && is_array($rawOptions)) {
                    $options = array_map('strval', array_keys($rawOptions));
                }

                $fields[] = [
                    'key' => (string) ($field['key'] ?? ''),
                    'type' => $type,
                    'label' => (string) ($field['label'] ?? ($field['key'] ?? 'Credential')),
                    'required' => (bool) ($field['required'] ?? false),
                    'default' => $field['default'] ?? null,
                    'placeholder' => (string) ($field['placeholder'] ?? ''),
                    'options' => $options,
                    'hint' => (string) ($field['hint'] ?? ''),
                ];
            }

            return array_values(array_filter($fields, static fn (array $field): bool => $field['key'] !== ''));
        }

        return array_values(array_map(
            static fn (array $field): array => [
                'key' => (string) ($field['key'] ?? ''),
                'type' => match ((string) ($field['type'] ?? 'text')) {
                    'string' => 'text',
                    default => (string) ($field['type'] ?? 'text'),
                },
                'label' => (string) ($field['label'] ?? ($field['key'] ?? 'Credential')),
                'required' => (bool) ($field['required'] ?? true),
                'default' => $field['default'] ?? null,
                'placeholder' => (string) ($field['placeholder'] ?? ''),
                'options' => [],
                'hint' => '',
            ],
            $provider->credentialFields(),
        ));
    }

    /**
     * @return array<string, array{key: string, type: string, label: string, required?: bool, default?: mixed, placeholder?: string, options?: list<string>, hint?: string}>
     */
    private function integrationConfigFieldMap(string $integration): array
    {
        if (! $this->container->bound(IntegrationManager::class)) {
            return [];
        }

        $provider = $this->container->make(IntegrationManager::class)->getAllProviders()[$integration] ?? null;
        if (! $provider instanceof ToolProvider) {
            return [];
        }

        $map = [];
        foreach ($this->integrationConfigFields($provider) as $field) {
            $map[$field['key']] = $field;
        }

        return $map;
    }

    private function integrationFieldDescription(array $field): string
    {
        $parts = [];

        if (($field['hint'] ?? '') !== '') {
            $parts[] = trim((string) $field['hint']);
        }

        if (($field['placeholder'] ?? '') !== '') {
            $parts[] = 'Placeholder: '.trim((string) $field['placeholder']);
        }

        $parts[] = ($field['required'] ?? false) ? 'Required for configuration.' : 'Optional.';

        return implode(' ', array_filter($parts));
    }

    private function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= 8) {
            return str_repeat('*', mb_strlen($value));
        }

        return mb_substr($value, 0, 4).'…'.mb_substr($value, -4);
    }
}
