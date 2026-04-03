<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Tool\Permission\PermissionMode;
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
        // Resolve catalogs and settings manager, then render the settings UI

        $catalog = $ctx->providers ?? $this->container->make(ProviderCatalog::class);
        $registry = $this->container->make(RelayRegistry::class);
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($ctx->sessionManager->getProject() ?? getcwd());

        $view = $this->buildSettingsView($ctx, $catalog, $settings);
        $result = $ctx->ui->showSettings($view);

        // No changes submitted
        if ($result === [] || ! is_array($result)) {
            return SlashCommandResult::continue();
        }

        $scope = (string) ($result['scope'] ?? 'project');
        $changes = is_array($result['changes'] ?? null) ? $result['changes'] : [];

        $customProvider = is_array($result['custom_provider'] ?? null) ? $result['custom_provider'] : null;
        $deleteCustomProvider = is_string($result['delete_custom_provider'] ?? null)
            ? trim((string) $result['delete_custom_provider'])
            : '';

        // Determine which provider the setup panel is configuring (may be custom)
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
        // Fall back to the provider's default model if the selected one isn't supported
        if (! $catalog->supportsModel($targetProvider, $targetModel)) {
            $fallbackModel = $catalog->defaultModel($targetProvider) ?? ($catalog->modelIds($targetProvider)[0] ?? $targetModel);
            $changes['agent.default_model'] = $fallbackModel;
            $targetModel = $fallbackModel;
            $ctx->ui->showNotice("Model reset to {$fallbackModel} for {$targetProvider}.");
        }

        foreach ($changes as $id => $value) {
            $stringValue = is_scalar($value) || $value === null ? (string) $value : '';

            // Dispatch each setting change to the appropriate handler
            match ($id) {
                'agent.mode' => $this->applyMode($ctx, $stringValue, $scope),
                'tools.default_permission_mode' => $this->applyPermissionMode($ctx, $stringValue, $scope),
                'agent.temperature' => $this->applyTemperature($ctx, $stringValue, $scope),
                'agent.max_tokens' => $this->applyMaxTokens($ctx, $stringValue, $scope),
                'agent.default_provider' => $this->applyProvider($ctx, $catalog, $registry, $settings, $stringValue, $scope),
                'agent.default_model' => $this->applyModel($ctx, $settings, $targetProvider, $stringValue, $scope),
                'provider.secret.api_key' => $this->storeApiKey($ctx, $catalog, $setupProvider !== '' ? $setupProvider : $targetProvider, $stringValue),
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
                default => $ctx->sessionManager->setSetting($id, $stringValue, $scope),
            };
        }

        // Refresh the status bar immediately so the user sees the new model/provider
        if (isset($changes['agent.default_provider']) || isset($changes['agent.default_model'])) {
            $modelCatalog = $ctx->models ?? $this->container->make(ModelCatalog::class);
            $ctx->ui->refreshRuntimeSelection($targetProvider, $targetModel, $modelCatalog->contextWindow($targetModel));
        }

        // Codex requires OAuth; prompt the user to authenticate if credentials are missing
        if (($changes['agent.default_provider'] ?? null) === 'codex' && ! isset($changes['provider.auth_action'])) {
            $flow = $this->container->make(CodexAuthFlow::class);
            if ($flow->current() === null) {
                $choice = $ctx->ui->askChoice('Codex needs ChatGPT authentication. Start login now?', [
                    ['label' => 'Browser login', 'detail' => 'Opens ChatGPT in your browser and waits for the callback on localhost.', 'recommended' => true],
                    ['label' => 'Device login', 'detail' => 'Shows a device code for headless or remote environments.', 'recommended' => false],
                    ['label' => 'Later', 'detail' => 'Keep Codex selected and authenticate later with `kosmokrator auth login codex`.', 'recommended' => false],
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

        return SlashCommandResult::continue();
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

            if ($categoryId === 'models') {
                $providerModelCount = count($catalog->modelIds((string) ($fields[0]['value'] ?? $currentProvider)));
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
            'models_provider_options' => $this->configuredProviderOptions($catalog, $currentProvider),
            'models_model_options_by_provider' => $this->configuredModelOptionsByProvider($catalog, $currentProvider),
            'provider_statuses' => $providerStatuses,
            'provider_api_key_display' => $this->providerApiKeyDisplay($catalog),
            'providers_by_id' => $this->providersById($catalog),
            'custom_provider_definitions' => $settings->customProviders(),
            'auth_action_options_by_provider' => $this->authActionOptionsByProvider($catalog),
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
     * @return list<string>
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
     * @return array<string, list<string>>
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
            'context.compact_threshold' => (string) ($ctx->agentLoop->getCompactor()?->getCompactThresholdPercent() ?? $fallback ?? 60),
            'context.prune_protect' => (string) ($ctx->agentLoop->getPruner()?->getProtectTokens() ?? $fallback ?? 40000),
            'context.prune_min_savings' => (string) ($ctx->agentLoop->getPruner()?->getMinSavings() ?? $fallback ?? 20000),
            default => $fallback === null ? '' : (string) $fallback,
        };
    }
}
