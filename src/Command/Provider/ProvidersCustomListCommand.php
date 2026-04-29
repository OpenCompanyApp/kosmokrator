<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderConfigurator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:custom:list', description: 'List custom providers')]
final class ProvidersCustomListCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providers = $this->container->make(ProviderConfigurator::class)->customProviders();
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'providers' => $providers]);
        } else {
            foreach (array_keys($providers) as $id) {
                $output->writeln($id);
            }
        }

        return Command::SUCCESS;
    }
}
