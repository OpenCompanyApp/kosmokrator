<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Coding\ShellSessionManager;
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

        // Session: resume or create new
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
        }

        return $this->repl($session);
    }

    private function repl(AgentSession $session): int
    {
        $taskStore = $this->container->make(TaskStore::class);
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepository::class);
        $models = $this->container->make(ModelCatalog::class);

        $providers = $this->container->make(ProviderCatalog::class);

        $registry = $this->buildSlashCommandRegistry();
        $ctx = new SlashCommandContext($session->ui, $session->agentLoop, $session->permissions, $session->sessionManager, $session->llm, $taskStore, $config, $settings, $session->orchestrator, $models, $providers);
        $nextInput = null;
        $nextInputShown = false;

        // Wire immediate command handler -- dispatches slash commands during agent execution
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
                $session->ui->showUserMessage($input);
            }
            $session->agentLoop->run($input);

            // Plan mode: show approval dialog after run completes
            if ($session->agentLoop->getMode() === AgentMode::Plan) {
                $approval = $session->ui->approvePlan($session->permissions->getPermissionMode()->value);

                if ($approval !== null) {
                    if ($approval['context'] === 'compact') {
                        $session->agentLoop->performCompaction();
                    } elseif ($approval['context'] === 'clear') {
                        $session->agentLoop->history()->clearKeepingLast();
                    }

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

    private function buildSlashCommandRegistry(): SlashCommandRegistry
    {
        $registry = new SlashCommandRegistry;

        $registry->register(new Slash\QuitCommand);
        $registry->register(new Slash\ClearCommand);
        $registry->register(new Slash\SeedCommand);
        $registry->register(new Slash\TheogonyCommand);
        $registry->register(new Slash\CompactCommand);
        $registry->register(new Slash\TasksClearCommand);
        $registry->register(new Slash\MemoriesCommand);
        $registry->register(new Slash\SessionsCommand);
        $registry->register(new Slash\ForgetCommand);
        $registry->register(new Slash\GuardianCommand);
        $registry->register(new Slash\ArgusCommand);
        $registry->register(new Slash\PrometheusCommand);
        $registry->register(new Slash\ModeCommand(AgentMode::Edit));
        $registry->register(new Slash\ModeCommand(AgentMode::Plan));
        $registry->register(new Slash\ModeCommand(AgentMode::Ask));
        $registry->register(new Slash\NewCommand);
        $registry->register(new Slash\ResumeCommand);
        $registry->register(new Slash\SettingsCommand($this->container));
        $registry->register(new Slash\AgentsCommand);

        return $registry;
    }
}
