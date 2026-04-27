<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:status', description: 'Show provider authentication status')]
final class ProvidersStatusCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(ProviderCatalog::class);
        $filter = $input->getArgument('provider');
        if (is_string($filter) && $filter !== '' && $catalog->provider($filter) === null) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown provider [{$filter}]."]);
            } else {
                $output->writeln("<error>Unknown provider [{$filter}].</error>");
            }

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($catalog->providers() as $provider) {
            if (is_string($filter) && $filter !== '' && $filter !== $provider->id) {
                continue;
            }

            $rows[] = [
                'provider' => $provider->id,
                'auth_mode' => $provider->authMode,
                'auth_status' => $catalog->authStatus($provider->id),
                'configured' => $provider->authMode === 'none'
                    || ($provider->authMode === 'api_key' && trim($catalog->apiKey($provider->id)) !== '')
                    || ($provider->authMode === 'oauth' && ! str_starts_with($catalog->authStatus($provider->id), 'Not authenticated')),
            ];
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'providers' => $rows]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Auth', 'Status'])
            ->setRows(array_map(static fn (array $row): array => [$row['provider'], $row['auth_mode'], $row['auth_status']], $rows))
            ->render();

        return Command::SUCCESS;
    }
}
