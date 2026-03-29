<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\EnvironmentContext;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
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
        $toolRegistry = $this->container->make(ToolRegistry::class);
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);
        $baseSystemPrompt = $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.')
            . InstructionLoader::gather()
            . EnvironmentContext::gather();
        $maxRounds = (int) $config->get('kosmokrator.agent.max_tool_rounds', 25);
        $agentLoop = new AgentLoop($llm, $ui, $log, $baseSystemPrompt, $maxRounds, $permissions, $models);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        return $this->repl($ui, $agentLoop, $permissions);
    }

    private function repl(UIManager $ui, AgentLoop $agentLoop, PermissionEvaluator $permissions): int
    {
        $nextInput = null;

        while (true) {
            $input = $nextInput ?? $ui->prompt();
            $nextInput = null;

            if ($input === '') {
                continue;
            }

            $command = strtolower($input);

            if (in_array($command, ['/quit', '/exit', '/q'])) {
                $ui->teardown();
                break;
            }

            if ($command === '/seed') {
                $ui->seedMockSession();
                continue;
            }

            if ($command === '/clear') {
                echo "\033[2J\033[H";
                continue;
            }

            if ($command === '/reset') {
                $agentLoop->history()->clear();
                $permissions->resetGrants();
                $permissions->setAutoApprove(false);
                $ui->showNotice('Conversation history cleared.');
                continue;
            }

            if ($command === '/prometheus') {
                $permissions->setAutoApprove(true);
                $ui->showNotice('⚡ Prometheus unbound — all tools auto-approved until next prompt.');
                continue;
            }

            if (in_array($command, ['/theogony', '/cosmogony'])) {
                $ui->playTheogony();
                continue;
            }

            if (in_array($command, ['/edit', '/plan', '/ask'])) {
                $mode = AgentMode::from(ltrim($command, '/'));
                $agentLoop->setMode($mode);
                $ui->showMode($mode->label(), $mode->color());
                $ui->showNotice("Switched to {$mode->label()} mode.");
                continue;
            }

            // Send to agent
            $ui->showUserMessage($input);
            $agentLoop->run($input);
            $permissions->setAutoApprove(false);

            // Check for messages queued during thinking
            $nextInput = $ui->consumeQueuedMessage();
        }

        $ui->teardown();

        return Command::SUCCESS;
    }
}
