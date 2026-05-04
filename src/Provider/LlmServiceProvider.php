<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ModelDefinitionSource;
use Kosmokrator\LLM\ModelPricingService;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use OpenCompany\PrismCodex\Codex;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismRelay\Caching\GeminiCacheStore;
use OpenCompany\PrismRelay\Caching\PromptCacheOrchestrator;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use OpenCompany\PrismRelay\RelayManager;
use Prism\Prism\PrismManager;
use Prism\Prism\PrismServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Registers the Prism LLM service provider, extends it with relay/codex drivers,
 * and binds the sync (PrismService) and async (AsyncLlmClient) LLM clients.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerPrism();
        $this->registerLlmClients();
    }

    /** Register the Prism LLM service provider and extend it with relay/codex drivers. */
    private function registerPrism(): void
    {
        // Simulate Laravel's Application interface just enough for PrismServiceProvider
        $this->container->instance('app', $this->container);

        /** @var Container $app */
        $app = $this->container;
        $provider = new PrismServiceProvider($app);
        $provider->register();

        $manager = $this->container->make(PrismManager::class);
        $providers = $this->container->make(RelayRegistry::class)->allProviders();
        unset($providers['codex']);
        (new RelayManager($providers))->register($manager);
        $manager->extend('codex', function ($app, array $config) {
            return new Codex(
                oauthService: $this->container->make(CodexOAuthService::class),
                url: $config['url'] ?? 'https://chatgpt.com/backend-api/codex',
                accountId: $config['account_id'] ?? null,
            );
        });
    }

    /** Register sync and async LLM clients with retry decorators. */
    private function registerLlmClients(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        $this->container->singleton(Relay::class, fn () => new Relay(
            promptCacheOrchestrator: new PromptCacheOrchestrator(
                prismManager: $this->container->make(PrismManager::class),
                geminiCacheStore: new GeminiCacheStore($home.'/.kosmo/cache/gemini-cache.json'),
            ),
        ));

        // Use lazy resolution via closures that read config at resolution time, not registration time,
        // so that late-boot config changes (e.g. SQLite settings injection) are reflected.
        $this->container->singleton(PrismService::class, function () {
            $config = $this->container->make('config');

            return new RetryableLlmClient(
                new PrismService(
                    provider: $config->get('kosmo.agent.default_provider', 'z'),
                    model: $config->get('kosmo.agent.default_model', 'claude-sonnet-4-20250514'),
                    systemPrompt: $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.'),
                    maxTokens: $config->get('kosmo.agent.max_tokens'),
                    temperature: $config->get('kosmo.agent.temperature', 0.0),
                    relay: $this->container->make(Relay::class),
                    registry: $this->container->make(RelayRegistry::class),
                ),
                $this->container->make(LoggerInterface::class),
                maxAttempts: 5,
            );
        });
        $this->container->singleton(LlmClientInterface::class, fn () => $this->container->make(PrismService::class));

        $this->container->singleton(AsyncLlmClient::class, function () {
            $config = $this->container->make('config');
            $registry = $this->container->make(RelayRegistry::class);
            $providers = $this->container->make(ProviderCatalog::class);
            $provider = $config->get('kosmo.agent.default_provider', 'z');
            $configuredUrl = $config->get("prism.providers.{$provider}.url");
            $providerUrl = rtrim(is_string($configuredUrl) && $configuredUrl !== '' ? $configuredUrl : $registry->url($provider), '/');

            return new RetryableLlmClient(
                new AsyncLlmClient(
                    apiKey: $providers->apiKey($provider),
                    baseUrl: $providerUrl,
                    model: $config->get('kosmo.agent.default_model', 'glm-5.1'),
                    systemPrompt: $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.'),
                    maxTokens: $config->get('kosmo.agent.max_tokens'),
                    temperature: $config->get('kosmo.agent.temperature', 0.0),
                    provider: $provider,
                    relay: $this->container->make(Relay::class),
                    registry: $this->container->make(RelayRegistry::class),
                ),
                $this->container->make(LoggerInterface::class),
                maxAttempts: 5,
            );
        });

        $this->container->singleton(ModelDefinitionSource::class, function () {
            $config = $this->container->make('config');

            return new ModelDefinitionSource(
                $config->get('models', []),
                $this->container->make(ProviderMeta::class),
            );
        });
        $this->container->singleton(ModelPricingService::class, fn () => new ModelPricingService(
            $this->container->make(ModelDefinitionSource::class),
        ));
        $this->container->singleton(ModelCatalog::class, function () {
            $config = $this->container->make('config');

            return new ModelCatalog(
                $config->get('models', []),
                $this->container->make(ProviderMeta::class),
            );
        });
    }
}
