<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationDocService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:docs', description: 'Read integration CLI and Lua docs')]
final class IntegrationDocsCommand extends Command
{
    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::OPTIONAL, 'Provider or provider.function')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text|markdown|json', 'text')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('json') ? 'json' : (string) $input->getOption('format');
        $output->writeln($this->container->make(IntegrationDocService::class)->render(
            page: is_string($input->getArgument('page')) ? $input->getArgument('page') : null,
            format: $format,
        ));

        return Command::SUCCESS;
    }
}
