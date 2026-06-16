<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\Codex\CodexOAuthService;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ModelDefinitionSource;
use Kosmokrator\LLM\ModelPricingService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderMeta;
use Kosmokrator\LLM\Relay;
use Kosmokrator\LLM\RelayProviderRegistry;
use Kosmokrator\LLM\RetryableLlmClient;
use Psr\Log\LoggerInterface;

/**
 * Registers KosmoKrator's native LLM client, provider metadata, and model catalog.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerLlmClients();
    }

    /** Register the native LLM client with retry decorators. */
    private function registerLlmClients(): void
    {
        $this->container->singleton(Relay::class, fn () => new Relay);

        // Use lazy resolution via closures that read config at resolution time, not registration time,
        // so that late-boot config changes (e.g. SQLite settings injection) are reflected.
        $this->container->singleton(AsyncLlmClient::class, function () {
            $config = $this->container->make('config');
            $registry = $this->container->make(RelayProviderRegistry::class);
            $providers = $this->container->make(ProviderCatalog::class);
            $provider = $config->get('kosmo.agent.default_provider', 'z');
            $configuredUrl = $config->get("prism.providers.{$provider}.url");
            $providerUrl = rtrim(is_string($configuredUrl) && $configuredUrl !== '' ? $configuredUrl : $registry->url($provider), '/');

            return new RetryableLlmClient(
                new AsyncLlmClient(
                    apiKey: $providers->apiKey($provider),
                    baseUrl: $providerUrl,
                    model: $config->get('kosmo.agent.default_model', 'glm-5.2'),
                    systemPrompt: $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.'),
                    maxTokens: $config->get('kosmo.agent.max_tokens'),
                    temperature: $config->get('kosmo.agent.temperature', 0.0),
                    provider: $provider,
                    relay: $this->container->make(Relay::class),
                    registry: $this->container->make(RelayProviderRegistry::class),
                    codexOAuth: $this->container->make(CodexOAuthService::class),
                    reasoningEffort: $config->get('kosmo.agent.reasoning_effort', 'max'),
                ),
                $this->container->make(LoggerInterface::class),
                maxAttempts: 5,
            );
        });
        $this->container->singleton(LlmClientInterface::class, fn () => $this->container->make(AsyncLlmClient::class));

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
