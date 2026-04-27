<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:status', description: 'Show integration activation and credential status')]
final class IntegrationStatusCommand extends Command
{
    use InteractsWithIntegrationOutput;

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
        $providers = $this->container->make(IntegrationCatalog::class)->byProvider();
        $data = [];

        foreach ($providers as $provider => $functions) {
            $first = $functions[0];
            $data[$provider] = [
                'active' => $first->active,
                'configured' => $first->configured,
                'accounts' => $first->accounts === [] ? ['default'] : array_merge(['default'], $first->accounts),
                'functions' => count($functions),
            ];
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, $data);

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($data as $provider => $row) {
            $rows[] = [
                $provider,
                $row['active'] ? 'yes' : 'no',
                $row['configured'] ? 'yes' : 'no',
                implode(', ', $row['accounts']),
                (string) $row['functions'],
            ];
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Active', 'Configured', 'Accounts', 'Functions'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
