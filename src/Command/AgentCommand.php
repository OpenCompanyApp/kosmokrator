<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Agent\Exception\MaxTurnsExceededException;
use Kosmokrator\Agent\Exception\TimeoutExceededException;
use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Setup\SetupFlowInterface;
use Kosmokrator\Skill\SkillDispatcher;
use Kosmokrator\Skill\SkillLoader;
use Kosmokrator\Skill\SkillRegistry;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\HeadlessRenderer;
use Kosmokrator\UI\OutputFormat;
use Kosmokrator\Update\UpdateChecker;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Main entry point — launches the KosmoKrator coding agent.
 *
 * Interactive mode: runs a REPL loop with TUI/ANSI renderer.
 * Headless mode (-p / --print or positional prompt): executes a single task and exits.
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
     * Registers CLI options for interactive and headless modes.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::OPTIONAL, 'Task prompt (enables headless mode)')
            ->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip the intro animation')
            ->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume last session for this project')
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Resume a specific session by ID')
            // Headless options
            ->addOption('print', 'p', InputOption::VALUE_NONE, 'Print response and exit (headless mode)')
            ->addOption('output-format', 'o', InputOption::VALUE_REQUIRED, 'Output format: text, json, stream-json', 'text')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Override model')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Agent mode: edit, plan, ask')
            ->addOption('yolo', null, InputOption::VALUE_NONE, 'Skip all permission checks (alias for --permission-mode prometheus)')
            ->addOption('permission-mode', null, InputOption::VALUE_REQUIRED, 'Permission mode: guardian, argus, prometheus')
            ->addOption('max-turns', 't', InputOption::VALUE_REQUIRED, 'Maximum agentic turns')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds')
            ->addOption('continue', 'c', InputOption::VALUE_NONE, 'Continue most recent session')
            ->addOption('append-system-prompt', null, InputOption::VALUE_REQUIRED, 'Append to system prompt')
            ->addOption('system-prompt', null, InputOption::VALUE_REQUIRED, 'Replace system prompt entirely')
            ->addOption('no-session', null, InputOption::VALUE_NONE, 'Don\'t persist session');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Detect headless mode: -p flag, positional prompt, or piped stdin
        $positionalPrompt = $input->getArgument('prompt');
        $isHeadless = $positionalPrompt !== null
            || $input->getOption('print')
            || (function_exists('posix_isatty') && ! posix_isatty(STDIN));

        if ($isHeadless) {
            return $this->runHeadless($input, $output);
        }

        return $this->runInteractive($input, $output);
    }

    /**
     * Run in headless (non-interactive) mode: execute a single task and exit.
     */
    private function runHeadless(InputInterface $input, OutputInterface $output): int
    {
        // 1. Resolve prompt: positional arg + optional stdin
        $prompt = $input->getArgument('prompt') ?? '';

        // Combine stdin with positional prompt (stdin appended after prompt)
        $stdinAvail = function_exists('posix_isatty') && ! posix_isatty(STDIN);
        if ($stdinAvail) {
            $stdin = stream_get_contents(STDIN);
            if ($stdin !== false && $stdin !== '') {
                $prompt = $prompt !== '' ? "{$prompt}\n\n{$stdin}" : $stdin;
            }
        }

        if (trim($prompt) === '') {
            fwrite(STDERR, "Error: No prompt provided. Pass a positional argument, use -p, or pipe stdin.\n");

            return 1;
        }

        // 2. Parse output format
        try {
            $format = OutputFormat::from($input->getOption('output-format'));
        } catch (\ValueError) {
            fwrite(STDERR, "Error: Invalid output format. Use: text, json, stream-json\n");

            return 1;
        }

        // 3. Resolve permission mode (--yolo takes precedence)
        $permissionMode = $input->getOption('permission-mode');
        if ($input->getOption('yolo')) {
            $permissionMode = 'prometheus';
        }

        // 4. Build headless session
        $builder = new AgentSessionBuilder($this->container);
        try {
            $session = $builder->buildHeadless($format, [
                'model' => $input->getOption('model'),
                'permission_mode' => $permissionMode,
                'agent_mode' => $input->getOption('mode'),
                'persist_session' => ! $input->getOption('no-session'),
                'system_prompt' => $input->getOption('system-prompt'),
                'append_system_prompt' => $input->getOption('append-system-prompt'),
                'max_turns' => $input->getOption('max-turns'),
                'timeout' => $input->getOption('timeout'),
            ]);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "Error: {$e->getMessage()}\n");
            fwrite(STDERR, "Run kosmokrator setup to configure your provider and API key.\n");

            return 1;
        }

        /** @var HeadlessRenderer $renderer */
        $renderer = $session->ui;

        // 5. Session resume for headless (--continue or --session)
        $resumeId = $input->getOption('session');
        if ($resumeId === null && ($input->getOption('continue') || $input->getOption('resume'))) {
            $resumeId = $session->sessionManager->latestSession();
        }

        if ($resumeId !== null) {
            $session->sessionManager->setCurrentSession($resumeId);
            $history = $session->sessionManager->loadHistory($resumeId);
            if ($history->count() > 0) {
                $session->agentLoop->setHistory($history);
            }
        } elseif (! $input->getOption('no-session')) {
            $modelName = $session->llm->getProvider().'/'.$session->llm->getModel();
            $session->sessionManager->createSession($modelName);
        }

        // 6. Install SIGINT handler for graceful cancellation
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($session) {
                $session->orchestrator?->cancelAll();
                $this->container->make(ShellSessionManager::class)->killAll();
                exit(130);
            });
            pcntl_signal(SIGTERM, function () use ($session) {
                $session->orchestrator?->cancelAll();
                $this->container->make(ShellSessionManager::class)->killAll();
                exit(143);
            });
        }

        // 7. Run the agent
        $renderer->showUserMessage($prompt);
        try {
            $result = $session->agentLoop->runHeadless($prompt);
        } catch (MaxTurnsExceededException $e) {
            $renderer->emitError("Agent exceeded maximum of {$e->maxTurns} turns.");
            if ($e->partialResult !== '') {
                $renderer->emitResult($e->partialResult, (int) $input->getOption('max-turns'), 0, 0);
            }

            return 2;
        } catch (TimeoutExceededException $e) {
            $renderer->emitError("Agent timed out after {$e->timeoutSeconds} seconds.");
            if ($e->partialResult !== '') {
                $renderer->emitResult($e->partialResult, 0, 0, 0);
            }

            return 2;
        } catch (\Throwable $e) {
            $renderer->emitError($e->getMessage());

            return 1;
        }

        // 8. Output the result
        // runHeadless() returns "Error: ..." on recoverable errors — treat as failure
        $isError = str_starts_with($result, 'Error: ');
        $tokensIn = $session->agentLoop->getSessionTokensIn();
        $tokensOut = $session->agentLoop->getSessionTokensOut();
        $renderer->emitResult($result, 0, $tokensIn, $tokensOut);

        // 9. Cleanup
        $session->orchestrator?->cancelAll();
        $this->container->make(ShellSessionManager::class)->killAll();

        return $isError ? 1 : 0;
    }

    /**
     * Run in interactive (REPL) mode.
     */
    private function runInteractive(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        $rendererPref = $input->getOption('renderer') ?: $config->get('kosmokrator.ui.renderer', 'auto');
        $animated = ! $input->getOption('no-animation') && $config->get('kosmokrator.ui.intro_animated', true);
        $setup = $this->container->make(SetupFlowInterface::class);

        if ($setup->needsProviderSetup()) {
            $completed = $setup->open(
                rendererPref: (string) $rendererPref,
                animated: $animated,
                showIntro: false,
                notice: 'No provider is configured yet. Finish setup first, then KosmoKrator will continue.',
            );

            if (! $completed) {
                return Command::FAILURE;
            }

            $animated = false;
        }

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
        if ($resumeId === null && ($input->getOption('continue') || $input->getOption('resume'))) {
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
                $session->ui->showNotice("Update available: v{$updateAvailable} (current: v{$currentVersion}). Run `kosmokrator update` to install.");
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
                $this->container->make(LoggerInterface::class)
                    ->warning('Completion sound hook failed', ['error' => $e->getMessage()]);
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
        $version = $this->getApplication()?->getVersion() ?? 'dev';

        return SlashCommandRegistryFactory::build($this->container, $version);
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
