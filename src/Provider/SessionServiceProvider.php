<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MemoryRepositoryInterface;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\MessageRepositoryInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SessionRepositoryInterface;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Session\SubagentOutputStore;
use Kosmokrator\Session\SwarmMetadataStore;
use Kosmokrator\Settings\SettingsManager;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Registers session persistence (repositories + SessionManager) and the
 * completion sound service.
 */
class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SessionRepository::class, fn () => new SessionRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->alias(SessionRepository::class, SessionRepositoryInterface::class);
        $this->container->singleton(MessageRepository::class, fn () => new MessageRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->alias(MessageRepository::class, MessageRepositoryInterface::class);
        $this->container->singleton(MemoryRepository::class, fn () => new MemoryRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->alias(MemoryRepository::class, MemoryRepositoryInterface::class);
        $this->container->singleton(SwarmMetadataStore::class, fn () => new SwarmMetadataStore(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->singleton(SubagentOutputStore::class, fn () => new SubagentOutputStore);
        $this->container->singleton(SessionManager::class, fn () => new SessionManager(
            sessions: $this->container->make(SessionRepositoryInterface::class),
            messages: $this->container->make(MessageRepositoryInterface::class),
            settings: $this->container->make(SettingsRepositoryInterface::class),
            memories: $this->container->make(MemoryRepositoryInterface::class),
            log: $this->container->make(LoggerInterface::class),
            configSettings: $this->container->make(SettingsManager::class),
            swarmMetadata: $this->container->make(SwarmMetadataStore::class),
            subagentOutputs: $this->container->make(SubagentOutputStore::class),
        ));

        // Completion sound — compose music via LLM after each agent response
        $this->container->singleton(CompletionSound::class, function () {
            $config = $this->container->make('config');
            $sessionId = $this->container->make(SessionManager::class)->currentSessionId() ?? 'default';

            // Build a dedicated LLM client for audio so we don't mutate the shared singleton
            $audioProvider = $config->get('kosmo.agent.audio_provider');
            $audioModel = $config->get('kosmo.agent.audio_model');
            $defaultProvider = $config->get('kosmo.agent.default_provider', 'z');
            $defaultModel = $config->get('kosmo.agent.default_model', 'claude-sonnet-4-20250514');

            $llm = new PrismService(
                provider: ($audioProvider !== null && $audioProvider !== '') ? $audioProvider : $defaultProvider,
                model: ($audioModel !== null && $audioModel !== '') ? $audioModel : $defaultModel,
                systemPrompt: $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.'),
                relay: $this->container->make(Relay::class),
                registry: $this->container->make(RelayRegistry::class),
            );

            return new CompletionSound(
                llm: $llm,
                log: $this->container->make(LoggerInterface::class),
                sessionId: $sessionId,
                enabled: $config->get('kosmo.audio.completion_sound', false),
                soundfont: str_replace('~', getenv('HOME') ?: sys_get_temp_dir(), $config->get('kosmo.audio.soundfont', '~/.kosmo/soundfonts/FluidR3_GM.sf2')),
                maxDuration: (int) $config->get('kosmo.audio.max_duration', 8),
                maxRetries: (int) $config->get('kosmo.audio.max_retries', 1),
                llmTimeoutSeconds: (int) $config->get('kosmo.audio.llm_timeout', 60),
            );
        });
    }
}
