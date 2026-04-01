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
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Tool\Permission\PermissionMode;

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
        return 'Open settings panel';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $catalog = $ctx->providers ?? $this->container->make(ProviderCatalog::class);
        $currentProvider = $ctx->llm->getProvider();
        $currentModel = $ctx->llm->getModel();

        $currentSettings = $this->buildSettingsView($ctx, $catalog, $currentProvider);
        $changes = $ctx->ui->showSettings($currentSettings);

        if ($changes === []) {
            return SlashCommandResult::continue();
        }

        $targetProvider = $changes['provider'] ?? $currentProvider;
        $targetModel = $changes['model'] ?? $currentModel;

        if (! $catalog->supportsModel($targetProvider, $targetModel)) {
            $fallbackModel = $catalog->defaultModel($targetProvider) ?? ($catalog->modelIds($targetProvider)[0] ?? $targetModel);
            $changes['model'] = $fallbackModel;
            $targetModel = $fallbackModel;
            $ctx->ui->showNotice("Model reset to {$fallbackModel} for {$targetProvider}.");
        }

        foreach ($changes as $id => $value) {
            match ($id) {
                'mode' => (function () use ($ctx, $value) {
                    $mode = AgentMode::from($value);
                    $ctx->agentLoop->setMode($mode);
                    $ctx->ui->showMode($mode->label(), $mode->color());
                    $ctx->sessionManager->setSetting('mode', $value);
                })(),
                'permission_mode' => (function () use ($ctx, $value) {
                    $mode = PermissionMode::from($value);
                    $ctx->permissions->setPermissionMode($mode);
                    $ctx->ui->setPermissionMode($mode->statusLabel(), $mode->color());
                    $ctx->sessionManager->setSetting('permission_mode', $value);
                })(),
                'memories' => $ctx->sessionManager->setSetting('memories', $value),
                'auto_compact' => $ctx->sessionManager->setSetting('auto_compact', $value),
                'compact_threshold' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getCompactor()?->setCompactThresholdPercent((int) $value);
                    $ctx->sessionManager->setSetting('compact_threshold', $value);
                })(),
                'prune_protect' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getPruner()?->setProtectTokens((int) $value);
                    $ctx->sessionManager->setSetting('prune_protect', $value);
                })(),
                'prune_min_savings' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getPruner()?->setMinSavings((int) $value);
                    $ctx->sessionManager->setSetting('prune_min_savings', $value);
                })(),
                'temperature' => (function () use ($ctx, $value) {
                    $ctx->llm->setTemperature((float) $value);
                    $ctx->sessionManager->setSetting('temperature', $value);
                })(),
                'max_tokens' => (function () use ($ctx, $value) {
                    $tokens = $value !== '' ? (int) $value : null;
                    $ctx->llm->setMaxTokens($tokens);
                    $ctx->sessionManager->setSetting('max_tokens', $value);
                })(),
                'subagent_concurrency' => $ctx->sessionManager->setSetting('subagent_concurrency', $value),
                'subagent_max_retries' => $ctx->sessionManager->setSetting('subagent_max_retries', $value),
                'provider' => $this->applyProvider($ctx, $value),
                'model' => $this->applyModel($ctx, $targetProvider, $value),
                'api_key' => $this->storeApiKey($ctx, $targetProvider, $value),
                'auth_action' => $this->handleAuthAction($ctx, $catalog, $targetProvider, $value),
                default => null,
            };
        }

        if (($changes['provider'] ?? null) === 'codex' && ! isset($changes['auth_action'])) {
            $flow = $this->container->make(CodexAuthFlow::class);
            if ($flow->current() === null) {
                $choice = $ctx->ui->askChoice('Codex needs ChatGPT authentication. Start login now?', [
                    ['label' => 'Browser login', 'detail' => 'Opens ChatGPT in your browser and waits for the callback on localhost.', 'recommended' => true],
                    ['label' => 'Device login', 'detail' => 'Shows a device code for headless or remote environments.', 'recommended' => false],
                    ['label' => 'Later', 'detail' => 'Keep Codex selected and authenticate later with `kosmokrator codex:login`.', 'recommended' => false],
                ]);

                if ($choice === 'Browser login') {
                    $this->handleAuthAction($ctx, $catalog, 'codex', 'login_browser');
                } elseif ($choice === 'Device login') {
                    $this->handleAuthAction($ctx, $catalog, 'codex', 'login_device');
                }
            }
        }

        $ctx->ui->showNotice('Settings updated: '.implode(', ', array_keys($changes)));

        return SlashCommandResult::continue();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettingsView(SlashCommandContext $ctx, ProviderCatalog $catalog, string $currentProvider): array
    {
        $memoriesEnabled = $ctx->sessionManager->getSetting('memories') ?? 'on';
        $autoCompact = $ctx->sessionManager->getSetting('auto_compact') ?? 'on';

        return [
            'mode' => $ctx->agentLoop->getMode()->value,
            'permission_mode' => $ctx->permissions->getPermissionMode()->value,
            'memories' => $memoriesEnabled,
            'auto_compact' => $autoCompact,
            'compact_threshold' => (string) ($ctx->agentLoop->getCompactor()?->getCompactThresholdPercent() ?? 60),
            'prune_protect' => (string) ($ctx->agentLoop->getPruner()?->getProtectTokens() ?? 40000),
            'prune_min_savings' => (string) ($ctx->agentLoop->getPruner()?->getMinSavings() ?? 20000),
            'temperature' => (string) ($ctx->llm->getTemperature() ?? 0.0),
            'max_tokens' => (string) ($ctx->llm->getMaxTokens() ?? ''),
            'subagent_concurrency' => (string) ($ctx->sessionManager->getSetting('subagent_concurrency')
                ?? $ctx->config->get('kosmokrator.agent.subagent_concurrency', 10)),
            'subagent_max_retries' => (string) ($ctx->sessionManager->getSetting('subagent_max_retries')
                ?? $ctx->config->get('kosmokrator.agent.subagent_max_retries', 2)),
            'provider' => $currentProvider,
            'model' => $ctx->llm->getModel(),
            'api_key' => $catalog->authMode($currentProvider) === 'api_key'
                ? $catalog->maskedCredential($currentProvider)
                : '(not used)',
            'auth_status' => $catalog->authStatus($currentProvider),
            'provider_options' => $catalog->providerOptions(),
            'model_options_by_provider' => $catalog->modelOptionsByProvider(),
            'provider_statuses' => $catalog->authStatuses(),
            'provider_auth_modes' => $catalog->authModes(),
            'provider_model_values' => $this->providerModelValues($ctx, $catalog, $currentProvider),
            'provider_api_key_display' => $this->providerApiKeyDisplay($catalog),
            'provider_defaults' => array_reduce(
                $catalog->providers(),
                static function (array $carry, $provider): array {
                    $carry[$provider->id] = $provider->defaultModel;

                    return $carry;
                },
                [],
            ),
        ];
    }

    private function applyProvider(SlashCommandContext $ctx, string $provider): void
    {
        $ctx->settings->set('global', 'agent.default_provider', $provider);

        if (self::requiresRestart($ctx->llm, $provider)) {
            $ctx->ui->showNotice("Provider saved as {$provider}. Restart session to switch runtime.");

            return;
        }

        $ctx->llm->setProvider($provider);
        $inner = self::innerClient($ctx->llm);

        if ($provider !== 'codex' && method_exists($inner, 'setBaseUrl')) {
            $inner->setBaseUrl(rtrim($ctx->config->get("prism.providers.{$provider}.url", ''), '/'));
        }

        if (method_exists($inner, 'setApiKey')) {
            $inner->setApiKey($provider === 'codex'
                ? ''
                : (string) ($ctx->settings->get('global', "provider.{$provider}.api_key")
                    ?? $ctx->config->get("prism.providers.{$provider}.api_key", '')));
        }
    }

    private function applyModel(SlashCommandContext $ctx, string $provider, string $model): void
    {
        $ctx->settings->set('global', 'agent.default_model', $model);
        $ctx->settings->set('global', "provider.{$provider}.last_model", $model);

        if (! self::requiresRestart($ctx->llm, $provider)) {
            $ctx->llm->setModel($model);
        }
    }

    private function storeApiKey(SlashCommandContext $ctx, string $provider, string $value): void
    {
        if ($value === '' || $provider === 'codex') {
            return;
        }

        $ctx->settings->set('global', "provider.{$provider}.api_key", $value);
        $inner = self::innerClient($ctx->llm);

        if (! self::requiresRestart($ctx->llm, $provider) && method_exists($inner, 'setApiKey')) {
            $inner->setApiKey($value);
        }
    }

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
                if (! self::requiresRestart($ctx->llm, $provider) && method_exists($inner, 'setApiKey')) {
                    $inner->setApiKey('');
                }
                $ctx->ui->showNotice("Cleared API key for {$provider}.");

                return;
            }

            if ($action === 'edit_key') {
                $key = trim($ctx->ui->askUser("Enter API key for {$provider}:"));
                if ($key !== '') {
                    $this->storeApiKey($ctx, $provider, $key);
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

    private static function requiresRestart(LlmClientInterface $llm, string $provider): bool
    {
        $inner = self::innerClient($llm);

        return $inner instanceof AsyncLlmClient && ! AsyncLlmClient::supportsProvider($provider);
    }

    private static function innerClient(LlmClientInterface $llm): LlmClientInterface
    {
        return $llm instanceof RetryableLlmClient ? $llm->inner() : $llm;
    }

    /**
     * @return array<string, string>
     */
    private function providerModelValues(SlashCommandContext $ctx, ProviderCatalog $catalog, string $currentProvider): array
    {
        $values = [];

        foreach ($catalog->providers() as $provider) {
            $stored = $ctx->settings->get('global', "provider.{$provider->id}.last_model");
            $current = $provider->id === $currentProvider ? $ctx->llm->getModel() : null;

            $candidate = $stored ?? $current ?? $provider->defaultModel;
            if (! $catalog->supportsModel($provider->id, $candidate)) {
                $candidate = $provider->defaultModel;
            }

            $values[$provider->id] = $candidate;
        }

        return $values;
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
                : '(not used)';
        }

        return $values;
    }
}
