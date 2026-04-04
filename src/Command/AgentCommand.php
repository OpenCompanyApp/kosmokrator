<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Skill\SkillDispatcher;
use Kosmokrator\Skill\SkillLoader;
use Kosmokrator\Skill\SkillRegistry;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Update\UpdateChecker;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Main entry point — launches the interactive KosmoKrator coding agent REPL.
 */
#[AsCommand(name: 'agent', description: 'Launch the KosmoKrator coding agent')]
class AgentCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    /**
     * Registers CLI options for renderer selection, animation toggle, and session resumption.
     */
    protected function configure(): void
    {
        $this->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip the intro animation');
        $this->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Resume last session for this project');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'Resume a specific session by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Build the agent session (UI + LLM + permissions) from container bindings.
        // Falls back to a friendly error when the provider is not yet configured.
        $config = $this->container->make('config');
        $rendererPref = $input->getOption('renderer') ?: $config->get('kosmokrator.ui.renderer', 'auto');
        $animated = ! $input->getOption('no-animation') && $config->get('kosmokrator.ui.intro_animated', true);

        $builder = new AgentSessionBuilder($this->container);

        try {
            $session = $builder->build($rendererPref, $animated);
        } catch (\RuntimeException $e) {
            $r = "\033[0m";
            $dim = "\033[38;5;245m";
            $accent = "\033[38;2;255;200;80m";
            $white = "\033[1;37m";

            echo "{$accent}  ⚡ {$e->getMessage()}{$r}\n";
            echo "{$dim}  Run {$white}kosmokrator setup{$dim} to configure your provider and API key.{$r}\n\n";

            return Command::FAILURE;
        }

        // Resume an existing session by ID or pick the latest one for this project.
        $resumeId = $input->getOption('session');
        if ($resumeId === null && $input->getOption('resume')) {
            $resumeId = $session->sessionManager->latestSession();
        }

        if ($resumeId !== null) {
            $session->sessionManager->setCurrentSession($resumeId);
            $history = $session->sessionManager->loadHistory($resumeId);
            if ($history->count() > 0) {
                $session->agentLoop->setHistory($history);
                $session->ui->replayHistory($history->messages());
                $session->ui->showNotice("Resumed session ({$resumeId})");
            }
        } else {
            $modelName = $session->llm->getProvider().'/'.$session->llm->getModel();
            $session->sessionManager->createSession($modelName);

            // Check for updates on clean session start
            $currentVersion = $this->getApplication()?->getVersion() ?? 'dev';
            $updateAvailable = (new UpdateChecker($currentVersion))->check();
            if ($updateAvailable !== null) {
                $session->ui->showNotice("Update available: v{$updateAvailable} (current: v{$currentVersion}). Run /update to install.");
            }
        }

        return $this->repl($session);
    }

    /**
     * Core REPL loop — reads user input, dispatches slash commands, and drives the agent.
     */
    private function repl(AgentSession $session): int
    {
        // Install signal handlers for graceful cleanup on SIGINT/SIGTERM.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $signalHandler = function () use ($session) {
                $session->orchestrator?->cancelAll();
                $this->container->make(ShellSessionManager::class)->killAll();
                $session->ui->teardown();
                exit(0);
            };
            pcntl_signal(SIGINT, $signalHandler);
            pcntl_signal(SIGTERM, $signalHandler);
        }

        $taskStore = $this->container->make(TaskStore::class);
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepositoryInterface::class);
        $models = $this->container->make(ModelCatalog::class);

        $providers = $this->container->make(ProviderCatalog::class);

        // Build skill system (user-defined $skills)
        // Discovers from: .kosmokrator/skills/, .agents/skills/, ~/.kosmokrator/skills/
        $projectRoot = $this->container->make('path.base');
        $skillLoader = new SkillLoader(
            $projectRoot,
            ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.kosmokrator/skills',
        );
        $skillRegistry = new SkillRegistry;
        $skillRegistry->load($skillLoader);
        $skillDispatcher = new SkillDispatcher($skillRegistry, $skillLoader, $session->ui);
        $session->ui->setSkillCompletions($skillRegistry->completions());

        $registry = $this->buildSlashCommandRegistry();
        $powerRegistry = $this->buildPowerCommandRegistry();
        $registry->register(new Slash\HelpCommand($registry, $powerRegistry));
        $ctx = new SlashCommandContext($session->ui, $session->agentLoop, $session->permissions, $session->sessionManager, $session->llm, $taskStore, $config, $settings, $session->orchestrator, $models, $providers);
        $nextInput = null;
        $nextInputShown = false;

        // Dispatch immediate slash commands (e.g. /guardian) even while the agent is mid-run.
        $session->ui->setImmediateCommandHandler(function (string $input) use ($registry, $ctx): bool {
            $command = $registry->resolve($input);
            if ($command === null || ! $command->immediate()) {
                return false;
            }
            $args = $registry->extractArgs($input, $command);
            $command->execute($args, $ctx);

            return true;
        });

        while (true) {
            $taskStore->clearTerminal();
            $session->ui->refreshTaskBar();

            $input = $nextInput ?? $session->ui->prompt();
            $alreadyShown = $nextInputShown;
            $nextInput = null;
            $nextInputShown = false;

            if (trim($input) === '') {
                continue;
            }

            // User skill dispatch — '$' prefix.
            if (str_starts_with($input, '$')) {
                $result = $skillDispatcher->dispatch(substr($input, 1));
                if ($result !== null) {
                    $nextInput = $result;
                }
                // Refresh completions in case skills were created or deleted
                $session->ui->setSkillCompletions($skillRegistry->completions());

                continue;
            }

            // Power command dispatch — ':' prefix, combinable chains.
            if ($powerRegistry->isPowerInput($input)) {
                $chain = $powerRegistry->parse($input);
                if ($chain === null) {
                    $session->ui->showNotice('Unknown power command. Type : to see available commands.');

                    continue;
                }

                // Validate all args before playing any animation.
                foreach ($chain as [$cmd, $cmdArgs]) {
                    if ($cmd->requiresArgs() && trim($cmdArgs) === '') {
                        $session->ui->showNotice("Usage: {$cmd->name()} <description>");

                        continue 2;
                    }
                }

                // Play animations sequentially.
                $chainCount = count($chain);
                foreach ($chain as $i => [$cmd, $cmdArgs]) {
                    $animClass = $cmd->animationClass();
                    $session->ui->playAnimation(new $animClass);
                    if ($i < $chainCount - 1) {
                        usleep(300_000);
                    }
                }

                // Inject combined prompt.
                $prompts = array_map(fn (array $pair) => $pair[0]->buildPrompt($pair[1]), $chain);
                $nextInput = implode("\n\n---\n\n", $prompts);

                continue;
            }

            // Slash command dispatch — non-immediate commands are only processed here at the prompt.
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

            // Unknown slash command — reject rather than sending to LLM
            if (str_starts_with($input, '/')) {
                $cmd = preg_split('/\s+/', $input, 2)[0];
                $session->ui->showNotice("Unknown command: {$cmd}. Type /help for available commands.");

                continue;
            }

            // Send to agent
            if (! $alreadyShown) {
                $session->ui->showUserMessage($input);
            }
            $session->agentLoop->run($input);

            // Auto-continue: if background subagents are running, wait for them
            // to finish, then feed their results back to the LLM automatically
            // so it can synthesize without requiring the user to type anything.
            if ($session->agentLoop->hasRunningBackgroundAgents() || $session->agentLoop->hasPendingBackgroundResults()) {
                while ($session->agentLoop->hasRunningBackgroundAgents()) {
                    \Amp\delay(1.0);
                }
                if ($session->agentLoop->hasPendingBackgroundResults()) {
                    $session->agentLoop->run('[system: all background agents have completed — their results follow]');
                }
            }

            // Completion sound: compose and play a musical piece reflecting what happened
            try {
                $sound = $this->container->make(CompletionSound::class);
                if ($sound->isEnabled()) {
                    $history = $session->agentLoop->history();
                    $messages = $history->messages();
                    // Find the last assistant message for content analysis
                    for ($i = count($messages) - 1; $i >= 0; $i--) {
                        if ($messages[$i] instanceof AssistantMessage
                            && $messages[$i]->content !== '') {
                            $sound->play(
                                $messages[$i]->content,
                                roundCount: 1,
                                projectName: basename(getcwd()),
                            );
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Never let the sound system break the REPL — but log it
                error_log('[CompletionSound] Hook failed: '.$e->getMessage());
            }

            // Plan mode: show approval dialog after run completes
            if ($session->agentLoop->getMode() === AgentMode::Plan) {
                $approval = $session->ui->approvePlan($session->permissions->getPermissionMode()->value);

                if ($approval !== null) {
                    if ($approval['context'] === 'compact') {
                        // Compact conversation history to free context window.
                        $session->agentLoop->performCompaction();
                    } elseif ($approval['context'] === 'clear') {
                        // Drop all history except the last assistant message.
                        $session->agentLoop->history()->clearKeepingLast();
                    }

                    // Switch to Edit mode and apply the user-chosen permission level.
                    $editMode = AgentMode::Edit;
                    $session->agentLoop->setMode($editMode);
                    $session->ui->showMode($editMode->label(), $editMode->color());
                    $session->sessionManager->setSetting('mode', 'edit');

                    $permMode = PermissionMode::from($approval['permission']);
                    $session->permissions->setPermissionMode($permMode);
                    $session->ui->setPermissionMode($permMode->statusLabel(), $permMode->color());
                    $session->sessionManager->setSetting('permission_mode', $permMode->value);

                    $nextInput = 'Implement the plan.';

                    continue;
                }
            }

            $nextInput = $session->ui->consumeQueuedMessage();
            $nextInputShown = $nextInput !== null; // queue messages are pre-displayed
        }

        $session->orchestrator?->cancelAll();
        $this->container->make(ShellSessionManager::class)->killAll();
        $session->ui->teardown();

        return Command::SUCCESS;
    }

    /**
     * Registers all available slash commands into a new registry.
     */
    private function buildSlashCommandRegistry(): SlashCommandRegistry
    {
        $registry = new SlashCommandRegistry;

        // Core commands
        $registry->register(new Slash\QuitCommand);
        // Session management commands
        $registry->register(new Slash\ClearCommand);
        $registry->register(new Slash\SeedCommand);
        $registry->register(new Slash\TheogonyCommand);
        $registry->register(new Slash\CompactCommand);
        $registry->register(new Slash\TasksClearCommand);
        $registry->register(new Slash\MemoriesCommand);
        $registry->register(new Slash\SessionsCommand);
        $registry->register(new Slash\ForgetCommand);

        // Agent mode switches
        $registry->register(new Slash\GuardianCommand);
        $registry->register(new Slash\ArgusCommand);
        $registry->register(new Slash\PrometheusCommand);
        $registry->register(new Slash\ModeCommand(AgentMode::Edit));
        $registry->register(new Slash\ModeCommand(AgentMode::Plan));
        $registry->register(new Slash\ModeCommand(AgentMode::Ask));

        // Utility commands
        $version = $this->getApplication()?->getVersion() ?? 'dev';
        $registry->register(new Slash\NewCommand);
        $registry->register(new Slash\ResumeCommand);
        $registry->register(new Slash\SettingsCommand($this->container));
        $registry->register(new Slash\AgentsCommand);
        $registry->register(new Slash\UpdateCommand($version));
        $registry->register(new Slash\FeedbackCommand($version));
        $registry->register(new Slash\RenameCommand);

        return $registry;
    }

    /**
     * Registers all power workflow commands into a new registry.
     *
     * Auto-discovers classes in src/Command/Power/ that implement PowerCommand
     * instead of requiring manual registration for each command.
     */
    private function buildPowerCommandRegistry(): PowerCommandRegistry
    {
        $registry = new PowerCommandRegistry;

        $powerDir = dirname(__DIR__).'/Command/Power';
        $namespace = 'Kosmokrator\\Command\\Power\\';

        foreach (glob($powerDir.'/*Command.php') as $file) {
            $className = $namespace.basename($file, '.php');

            if (! class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->implementsInterface(PowerCommand::class) && ! $reflection->isAbstract()) {
                $registry->register($reflection->newInstance());
            }
        }

        return $registry;
    }
}
