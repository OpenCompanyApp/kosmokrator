<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\RendererInterface;

/**
 * Immutable value object carrying every dependency a slash command may need (UI, agent loop, sessions, etc.).
 */
readonly class SlashCommandContext
{
    public function __construct(
        public RendererInterface $ui,
        public AgentLoop $agentLoop,
        public PermissionEvaluator $permissions,
        public SessionManager $sessionManager,
        public LlmClientInterface $llm,
        public TaskStore $taskStore,
        public Repository $config,
        public SettingsRepositoryInterface $settings,
        public ?SubagentOrchestrator $orchestrator = null,
        public ?ModelCatalog $models = null,
        public ?ProviderCatalog $providers = null,
    ) {}
}
