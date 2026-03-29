<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
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

        $ui = new UIManager($rendererPref);
        $ui->initialize();
        $ui->renderIntro($animated);
        $ui->showWelcome();

        $llm = $this->container->make(PrismService::class);
        $toolRegistry = $this->container->make(ToolRegistry::class);
        $maxRounds = $config->get('kosmokrator.agent.max_tool_rounds', 25);
        $agentLoop = new AgentLoop($llm, $ui, $maxRounds);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        return $this->repl($ui, $agentLoop);
    }

    private function repl(UIManager $ui, AgentLoop $agentLoop): int
    {
        while (true) {
            $input = $ui->prompt();

            if ($input === '') {
                continue;
            }

            $command = strtolower($input);

            if (in_array($command, ['/quit', '/exit', '/q'])) {
                echo "\n\033[38;5;245m  Farewell, Kosmokrator.\033[0m\n\n";
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
                echo "\n\033[38;5;245m  Conversation history cleared.\033[0m\n\n";
                continue;
            }

            // Send to agent
            echo "\n";
            $agentLoop->run($input);
        }

        $ui->teardown();

        return Command::SUCCESS;
    }
}
