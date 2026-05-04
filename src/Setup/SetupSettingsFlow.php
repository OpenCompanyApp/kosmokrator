<?php

declare(strict_types=1);

namespace Kosmokrator\Setup;

use Amp\Cancellation;
use Generator;
use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Slash\SettingsCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use Psr\Log\LoggerInterface;

final class SetupSettingsFlow implements SetupFlowInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function needsProviderSetup(): bool
    {
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $providers = $this->container->make(ProviderCatalog::class);
        $config = $this->container->make('config');

        $provider = trim((string) ($settings->get('agent.default_provider')
            ?? $config->get('kosmo.agent.default_provider', 'z')));

        if ($provider === '' || $providers->provider($provider) === null) {
            return true;
        }

        return match ($providers->authMode($provider)) {
            'none' => false,
            'oauth' => ! str_starts_with($providers->authStatus($provider), 'Authenticated'),
            default => trim($providers->apiKey($provider)) === '',
        };
    }

    public function open(string $rendererPref = 'auto', bool $animated = false, bool $showIntro = false, ?string $notice = null): bool
    {
        $ui = new UIManager($rendererPref);
        $ui->initialize();

        try {
            if ($showIntro) {
                $ui->renderIntro($animated);
            }

            if ($notice !== null && trim($notice) !== '') {
                $ui->showNotice(trim($notice));
            }

            $ctx = $this->makeContext($ui);
            $settings = new SettingsCommand($this->container);
            $settings->openWorkspace($ctx, [
                'title' => 'Setup',
                'scope' => 'global',
                'initial_category' => 'provider_setup',
            ]);

            return ! $this->needsProviderSetup();
        } finally {
            $ui->teardown();
        }
    }

    private function makeContext(UIManager $ui): SlashCommandContext
    {
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $providers = $this->container->make(ProviderCatalog::class);
        $models = $this->container->make(ModelCatalog::class);
        $provider = trim((string) ($settings->get('agent.default_provider')
            ?? $this->container->make('config')->get('kosmo.agent.default_provider', 'z')));
        $model = trim((string) ($settings->getProviderLastModel($provider)
            ?? $settings->get('agent.default_model')
            ?? $providers->defaultModel($provider)
            ?? ($providers->modelIds($provider)[0] ?? '')));

        $llm = new class($provider !== '' ? $provider : 'z', $model) implements LlmClientInterface
        {
            public function __construct(
                private string $provider,
                private string $model,
            ) {}

            public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
            {
                throw new \RuntimeException('Setup flow does not execute LLM requests.');
            }

            public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): Generator
            {
                yield from [];
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function setSystemPrompt(string $prompt): void {}

            public function getProvider(): string
            {
                return $this->provider;
            }

            public function setProvider(string $provider): void
            {
                $this->provider = $provider;
            }

            public function getModel(): string
            {
                return $this->model;
            }

            public function setModel(string $model): void
            {
                $this->model = $model;
            }

            public function getTemperature(): int|float|null
            {
                return null;
            }

            public function setTemperature(int|float|null $temperature): void {}

            public function getMaxTokens(): ?int
            {
                return null;
            }

            public function setMaxTokens(?int $maxTokens): void {}

            public function getReasoningEffort(): string
            {
                return 'off';
            }

            public function setReasoningEffort(string $effort): void {}

            public function setApiKey(string $apiKey): void {}

            public function setBaseUrl(string $baseUrl): void {}
        };

        $sessionManager = $this->container->make(SessionManager::class);
        $sessionManager->setProject(InstructionLoader::gitRoot() ?? getcwd());
        $permissions = $this->container->make(PermissionEvaluator::class);
        $taskStore = $this->container->make(TaskStore::class);

        $agentLoop = new AgentLoop(
            $llm,
            $ui,
            $this->container->make(LoggerInterface::class),
            'Setup settings flow.',
            $permissions,
            $models,
            $taskStore,
            $sessionManager,
        );

        return new SlashCommandContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $permissions,
            sessionManager: $sessionManager,
            llm: $llm,
            taskStore: $taskStore,
            config: $this->container->make('config'),
            settings: $this->container->make(SettingsRepositoryInterface::class),
            providers: $providers,
            models: $models,
        );
    }
}
