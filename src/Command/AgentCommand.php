<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\EnvironmentContext;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\ContextPruner;
use Kosmokrator\Agent\ToolResultDeduplicator;
use Kosmokrator\Agent\MemoryInjector;
use Kosmokrator\Agent\OutputTruncator;
use Kosmokrator\Command\Slash;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandRegistry;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent', description: 'Launch the KosmoKrator coding agent')]
class AgentCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip the intro animation');
        $this->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Resume last session for this project');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'Resume a specific session by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        $rendererPref = $input->getOption('renderer') ?: $config->get('kosmokrator.ui.renderer', 'auto');
        $animated = ! $input->getOption('no-animation') && $config->get('kosmokrator.ui.intro_animated', true);

        // Always show the intro first
        $ui = new UIManager($rendererPref);
        $ui->initialize();
        $ui->renderIntro($animated);
        $ui->showWelcome();

        // Check if API key is configured — prompt setup if not
        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $apiKey = $config->get("prism.providers.{$provider}.api_key", '');

        if ($apiKey === '' || $apiKey === null) {
            $r = "\033[0m";
            $dim = "\033[38;5;245m";
            $accent = "\033[38;2;255;200;80m";
            $white = "\033[1;37m";

            echo "{$accent}  ⚡ No API key configured.{$r}\n";
            echo "{$dim}  Run {$white}kosmokrator setup{$dim} to configure your provider and API key.{$r}\n\n";

            return Command::FAILURE;
        }

        $log = $this->container->make(LoggerInterface::class);
        $log->info('KosmoKrator started', ['renderer' => $ui->getActiveRenderer(), 'provider' => $provider]);

        $llm = ($ui->getActiveRenderer() === 'tui')
            ? $this->container->make(AsyncLlmClient::class)
            : $this->container->make(PrismService::class);

        // Wire retry notification so the user sees feedback during backoff
        if ($llm instanceof RetryableLlmClient) {
            $llm->setOnRetry(function (int $attempt, int $max, float $delay, string $reason) use ($ui) {
                $delaySec = (int) ceil($delay);
                $ui->showNotice("⟳ Rate limited — retrying in {$delaySec}s (attempt {$attempt}/{$max})");
            });
        }

        $toolRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry->register(new \Kosmokrator\Tool\AskUserTool($ui));
        $toolRegistry->register(new \Kosmokrator\Tool\AskChoiceTool($ui));
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);
        $sessionManager = $this->container->make(SessionManager::class);

        // Set project scope for settings/memories
        $project = InstructionLoader::gitRoot() ?? getcwd();
        $sessionManager->setProject($project);

        // Load persisted settings
        $this->applyPersistedSettings($sessionManager, $llm, $permissions);

        // Set initial permission mode on UI
        $permMode = $permissions->getPermissionMode();
        $ui->setPermissionMode($permMode->statusLabel(), $permMode->color());

        // Build system prompt: base + memories + instructions + environment
        $memoriesEnabled = ($sessionManager->getSetting('memories') ?? 'on') !== 'off';
        $memories = $memoriesEnabled ? $sessionManager->getMemories() : [];

        $baseSystemPrompt = $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.')
            . MemoryInjector::format($memories)
            . InstructionLoader::gather()
            . EnvironmentContext::gather();
        $taskStore = $this->container->make(TaskStore::class);
        $ui->setTaskStore($taskStore);
        $autoCompactEnabled = ($sessionManager->getSetting('auto_compact') ?? 'on') !== 'off';
        $compactThreshold = (int) ($sessionManager->getSetting('compact_threshold')
            ?? $config->get('kosmokrator.context.compact_threshold', 60));
        $compactor = $autoCompactEnabled ? new ContextCompactor($llm, $models, $log, $compactThreshold) : null;

        $truncator = new OutputTruncator(
            maxLines: (int) $config->get('kosmokrator.context.max_output_lines', 2000),
            maxBytes: (int) $config->get('kosmokrator.context.max_output_bytes', 50_000),
        );

        $pruneProtect = (int) ($sessionManager->getSetting('prune_protect') ?? $config->get('kosmokrator.context.prune_protect', 40_000));
        $pruneMinSavings = (int) ($sessionManager->getSetting('prune_min_savings') ?? $config->get('kosmokrator.context.prune_min_savings', 20_000));
        $pruner = new ContextPruner($pruneProtect, $pruneMinSavings);
        $deduplicator = new ToolResultDeduplicator();

        $memoryWarningThreshold = (int) $config->get('kosmokrator.context.memory_warning_mb', 50) * 1024 * 1024;
        $agentLoop = new AgentLoop($llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore, $sessionManager, $compactor, $truncator, $pruner, $deduplicator, $memoryWarningThreshold);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        // Session: resume or create new
        $resumeId = $input->getOption('session');
        if ($resumeId === null && $input->getOption('resume')) {
            $resumeId = $sessionManager->latestSession();
        }

        if ($resumeId !== null) {
            $sessionManager->setCurrentSession($resumeId);
            $history = $sessionManager->loadHistory($resumeId);
            if ($history->count() > 0) {
                $agentLoop->setHistory($history);
                $ui->replayHistory($history->messages());
                $ui->showNotice("Resumed session ({$resumeId})");
            }
        } else {
            $modelName = $llm->getProvider() . '/' . $llm->getModel();
            $sessionManager->createSession($modelName);
        }

        return $this->repl($ui, $agentLoop, $permissions, $llm, $sessionManager);
    }

    private function repl(UIManager $ui, AgentLoop $agentLoop, PermissionEvaluator $permissions, LlmClientInterface $llm, SessionManager $sessionManager): int
    {
        $taskStore = $this->container->make(TaskStore::class);
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepository::class);

        $registry = $this->buildSlashCommandRegistry();
        $ctx = new SlashCommandContext($ui, $agentLoop, $permissions, $sessionManager, $llm, $taskStore, $config, $settings);
        $nextInput = null;
        $nextInputShown = false;

        while (true) {
            $taskStore->clearTerminal();
            $ui->refreshTaskBar();

            $input = $nextInput ?? $ui->prompt();
            $alreadyShown = $nextInputShown;
            $nextInput = null;
            $nextInputShown = false;

            if ($input === '') {
                continue;
            }

            // Slash command dispatch
            $command = $registry->resolve($input);
            if ($command !== null) {
                $args = $registry->extractArgs($input, $command);
                $result = $command->execute($args, $ctx);

                if ($result->action === SlashCommandAction::Quit) {
                    break;
                }
                if ($result->action === SlashCommandAction::Inject) {
                    $nextInput = $result->input;
                }
                continue;
            }

            // Send to agent
            if (! $alreadyShown) {
                $ui->showUserMessage($input);
            }
            $agentLoop->run($input);

            // Plan mode: show approval dialog after run completes
            if ($agentLoop->getMode() === AgentMode::Plan) {
                $approval = $ui->approvePlan($permissions->getPermissionMode()->value);

                if ($approval !== null) {
                    if ($approval['context'] === 'compact') {
                        $agentLoop->performCompaction();
                    } elseif ($approval['context'] === 'clear') {
                        $agentLoop->history()->clearKeepingLast();
                    }

                    $editMode = AgentMode::Edit;
                    $agentLoop->setMode($editMode);
                    $ui->showMode($editMode->label(), $editMode->color());
                    $sessionManager->setSetting('mode', 'edit');

                    $permMode = PermissionMode::from($approval['permission']);
                    $permissions->setPermissionMode($permMode);
                    $ui->setPermissionMode($permMode->statusLabel(), $permMode->color());
                    $sessionManager->setSetting('permission_mode', $permMode->value);

                    $nextInput = 'Implement the plan.';
                    continue;
                }
            }

            $nextInput = $ui->consumeQueuedMessage();
            $nextInputShown = $nextInput !== null; // queue messages are pre-displayed
        }

        $ui->teardown();

        return Command::SUCCESS;
    }

    private function buildSlashCommandRegistry(): SlashCommandRegistry
    {
        $registry = new SlashCommandRegistry();

        $registry->register(new Slash\QuitCommand());
        $registry->register(new Slash\ClearCommand());
        $registry->register(new Slash\SeedCommand());
        $registry->register(new Slash\TheogonyCommand());
        $registry->register(new Slash\CompactCommand());
        $registry->register(new Slash\TasksClearCommand());
        $registry->register(new Slash\MemoriesCommand());
        $registry->register(new Slash\SessionsCommand());
        $registry->register(new Slash\ForgetCommand());
        $registry->register(new Slash\GuardianCommand());
        $registry->register(new Slash\ArgusCommand());
        $registry->register(new Slash\PrometheusCommand());
        $registry->register(new Slash\ModeCommand(AgentMode::Edit));
        $registry->register(new Slash\ModeCommand(AgentMode::Plan));
        $registry->register(new Slash\ModeCommand(AgentMode::Ask));
        $registry->register(new Slash\NewCommand());
        $registry->register(new Slash\ResumeCommand());
        $registry->register(new Slash\SettingsCommand());

        return $registry;
    }

    private function applyPersistedSettings(SessionManager $sm, LlmClientInterface $llm, PermissionEvaluator $permissions): void
    {
        $temp = $sm->getSetting('temperature');
        if ($temp !== null) {
            $llm->setTemperature((float) $temp);
        }

        $maxTokens = $sm->getSetting('max_tokens');
        if ($maxTokens !== null) {
            $llm->setMaxTokens((int) $maxTokens);
        }

        $permMode = $sm->getSetting('permission_mode');
        if ($permMode !== null) {
            $mode = PermissionMode::tryFrom($permMode);
            if ($mode !== null) {
                $permissions->setPermissionMode($mode);
            }
        } else {
            // Backward compat: old auto_approve setting
            $autoApprove = $sm->getSetting('auto_approve');
            if ($autoApprove === 'on') {
                $permissions->setPermissionMode(PermissionMode::Prometheus);
            }
        }
    }

}
