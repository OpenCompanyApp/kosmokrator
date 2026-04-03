<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MemoryRepositoryInterface;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\MessageRepositoryInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SessionRepositoryInterface;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
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
        $this->container->singleton(SessionManager::class, fn () => new SessionManager(
            sessions: $this->container->make(SessionRepositoryInterface::class),
            messages: $this->container->make(MessageRepositoryInterface::class),
            settings: $this->container->make(SettingsRepositoryInterface::class),
            memories: $this->container->make(MemoryRepositoryInterface::class),
            log: $this->container->make(LoggerInterface::class),
            configSettings: $this->container->make(SettingsManager::class),
        ));

        // Completion sound — compose music via LLM after each agent response
        $this->container->singleton(CompletionSound::class, function () {
            $config = $this->container->make('config');
            $sessionId = $this->container->make(SessionManager::class)->currentSessionId() ?? 'default';

            // Resolve audio model override — if set, apply to a cloned LLM client
            $llm = $this->container->make(LlmClientInterface::class);
            $audioProvider = $config->get('kosmokrator.agent.audio_provider');
            $audioModel = $config->get('kosmokrator.agent.audio_model');

            if ($audioProvider !== null && $audioProvider !== '') {
                $llm->setProvider($audioProvider);
            }
            if ($audioModel !== null && $audioModel !== '') {
                $llm->setModel($audioModel);
            }

            return new CompletionSound(
                llm: $llm,
                log: $this->container->make(LoggerInterface::class),
                sessionId: $sessionId,
                enabled: $config->get('kosmokrator.audio.completion_sound', false),
                soundfont: str_replace('~', getenv('HOME') ?: sys_get_temp_dir(), $config->get('kosmokrator.audio.soundfont', '~/.kosmokrator/soundfonts/FluidR3_GM.sf2')),
                maxDuration: (int) $config->get('kosmokrator.audio.max_duration', 8),
                maxRetries: (int) $config->get('kosmokrator.audio.max_retries', 1),
                llmTimeoutSeconds: (int) $config->get('kosmokrator.audio.llm_timeout', 60),
            );
        });
    }
}
