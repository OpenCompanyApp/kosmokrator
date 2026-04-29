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

#[AsCommand(name: 'integrations:list', description: 'List available integration providers and functions')]
final class IntegrationListCommand extends Command
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
        $catalog = $this->container->make(IntegrationCatalog::class);
        $providers = $catalog->byProvider();

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'providers' => array_map(
                    static fn (array $functions): array => [
                        'active' => $functions[0]->active ?? false,
                        'configured' => $functions[0]->configured ?? false,
                        'auth_strategy' => $functions[0]->capabilities['auth_strategy'] ?? 'none',
                        'cli_setup_supported' => $functions[0]->capabilities['cli_setup_supported'] ?? true,
                        'cli_runtime_supported' => $functions[0]->capabilities['cli_runtime_supported'] ?? true,
                        'compatibility_summary' => $functions[0]->capabilities['compatibility_summary'] ?? '',
                        'functions' => array_map(static fn ($function): array => $function->toArray(), $functions),
                    ],
                    $providers,
                ),
            ]);

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($providers as $provider => $functions) {
            $rows[] = [
                $provider,
                (string) count($functions),
                ($functions[0]->active ?? false) ? 'active' : (($functions[0]->configured ?? false) ? 'disabled' : 'inactive'),
                $functions[0]->capabilities['compatibility_summary'] ?? '',
                implode(', ', array_slice(array_map(static fn ($f): string => $f->function, $functions), 0, 5)).(count($functions) > 5 ? ', ...' : ''),
            ];
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Functions', 'Status', 'Compatibility', 'Examples'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
