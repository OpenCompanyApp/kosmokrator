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
        $config = $this->container->make('config');
        $registry = $this->container->make(RelayRegistry::class);
        $providers = $this->container->make(ProviderCatalog::class);
        $log = $this->container->make(LoggerInterface::class);
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        $this->container->singleton(Relay::class, fn () => new Relay(
            promptCacheOrchestrator: new PromptCacheOrchestrator(
                prismManager: $this->container->make(PrismManager::class),
                geminiCacheStore: new GeminiCacheStore($home.'/.kosmokrator/cache/gemini-cache.json'),
            ),
        ));

        $this->container->singleton(PrismService::class, fn () => new RetryableLlmClient(
            new PrismService(
                provider: $config->get('kosmokrator.agent.default_provider', 'z'),
                model: $config->get('kosmokrator.agent.default_model', 'claude-sonnet-4-20250514'),
                systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
                maxTokens: $config->get('kosmokrator.agent.max_tokens'),
                temperature: $config->get('kosmokrator.agent.temperature', 0.0),
                relay: $this->container->make(Relay::class),
                registry: $this->container->make(RelayRegistry::class),
            ),
            $log,
        ));
        $this->container->singleton(LlmClientInterface::class, fn () => $this->container->make(PrismService::class));

        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $providerUrl = rtrim($registry->url($provider), '/');
        $this->container->singleton(AsyncLlmClient::class, fn () => new RetryableLlmClient(
            new AsyncLlmClient(
                apiKey: $providers->apiKey($provider),
                baseUrl: $providerUrl,
                model: $config->get('kosmokrator.agent.default_model', 'GLM-5.1'),
                systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
                maxTokens: $config->get('kosmokrator.agent.max_tokens'),
                temperature: $config->get('kosmokrator.agent.temperature', 0.0),
                provider: $provider,
                relay: $this->container->make(Relay::class),
                registry: $this->container->make(RelayRegistry::class),
            ),
            $log,
        ));

        $this->container->singleton(ModelDefinitionSource::class, fn () => new ModelDefinitionSource(
            $config->get('models', []),
            $this->container->make(ProviderMeta::class),
        ));
        $this->container->singleton(ModelPricingService::class, fn () => new ModelPricingService(
            $this->container->make(ModelDefinitionSource::class),
        ));
        $this->container->singleton(ModelCatalog::class, fn () => new ModelCatalog(
            $config->get('models', []),
            $this->container->make(ProviderMeta::class),
        ));
    }
}
