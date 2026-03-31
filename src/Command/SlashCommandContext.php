<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;

readonly class SlashCommandContext
{
    public function __construct(
        public UIManager $ui,
        public AgentLoop $agentLoop,
        public PermissionEvaluator $permissions,
        public SessionManager $sessionManager,
        public LlmClientInterface $llm,
        public TaskStore $taskStore,
        public Repository $config,
        public SettingsRepository $settings,
        public ?SubagentOrchestrator $orchestrator = null,
        public ?ModelCatalog $models = null,
    ) {}
}
