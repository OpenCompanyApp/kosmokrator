<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Agent\ContextPipelineFactory;
use Kosmokrator\Agent\LlmClientFactory;
use Kosmokrator\Agent\SessionSettingsApplier;
use Kosmokrator\Agent\SubagentPipelineFactory;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Registers agent session factories: LlmClientFactory, ContextPipelineFactory,
 * SubagentPipelineFactory, SessionSettingsApplier, and AgentSessionBuilder.
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LlmClientFactory::class, fn () => new LlmClientFactory(
            $this->container,
        ));

        $this->container->singleton(ContextPipelineFactory::class, function () {
            $config = $this->container->make('config');

            return new ContextPipelineFactory(
                sessionManager: $this->container->make(SessionManager::class),
                models: $this->container->make(ModelCatalog::class),
                taskStore: $this->container->make(TaskStore::class),
                log: $this->container->make(LoggerInterface::class),
                config: $config->get('kosmo', []),
            );
        });

        $this->container->singleton(SubagentPipelineFactory::class, function () {
            $config = $this->container->make('config');
            $kosmoConfig = $config->get('kosmo', []);
            $prismProviders = $config->get('prism.providers', []);

            return new SubagentPipelineFactory(
                sessionManager: $this->container->make(SessionManager::class),
                providers: $this->container->make(ProviderCatalog::class),
                registry: $this->container->make(RelayRegistry::class),
                models: $this->container->make(ModelCatalog::class),
                relay: $this->container->make(Relay::class),
                log: $this->container->make(LoggerInterface::class),
                config: array_merge($kosmoConfig, ['prism_providers' => $prismProviders]),
            );
        });

        $this->container->singleton(SessionSettingsApplier::class, function () {
            $config = $this->container->make('config');

            return new SessionSettingsApplier(
                sessionManager: $this->container->make(SessionManager::class),
                config: $config->get('kosmo', []),
            );
        });

        $this->container->singleton(AgentSessionBuilder::class, fn () => new AgentSessionBuilder(
            $this->container,
        ));
    }
}
