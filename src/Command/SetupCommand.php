<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Setup\SetupFlowInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'setup', description: 'Open setup-focused settings for provider and model configuration')]
class SetupCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto')
            ->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip any intro animation before opening setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $completed = $this->container->make(SetupFlowInterface::class)->open(
            rendererPref: (string) $input->getOption('renderer'),
            animated: ! $input->getOption('no-animation'),
            showIntro: false,
            notice: 'Open settings to configure your default provider, model, and credentials.',
        );

        if (! $completed) {
            $output->writeln('<error>Setup incomplete. Configure a provider before continuing.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Setup complete. Run `kosmokrator` to start.</info>');

        return Command::SUCCESS;
    }
}
