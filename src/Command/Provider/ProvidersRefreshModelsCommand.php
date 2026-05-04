<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ModelDiscovery\ModelDiscoveryService;
use Kosmokrator\LLM\ProviderCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:refresh-models', description: 'Refresh cached model inventory from provider APIs')]
final class ProvidersRefreshModelsCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider ID. Omit to refresh configured providers with credentials.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(ProviderCatalog::class);
        $discovery = $this->container->make(ModelDiscoveryService::class);
        $providerArg = is_string($input->getArgument('provider')) ? trim($input->getArgument('provider')) : '';
        $providers = $providerArg !== ''
            ? [$providerArg]
            : array_values(array_filter(
                array_map(static fn ($provider): string => $provider->id, $catalog->providers()),
                static fn (string $provider): bool => $discovery->canDiscoverLive($provider),
            ));

        $results = [];
        $failed = false;
        foreach ($providers as $provider) {
            try {
                $result = $discovery->refresh($provider);
                $results[] = [
                    'provider' => $provider,
                    'success' => true,
                    'source' => $result->source,
                    'model_count' => count($result->models),
                    'fetched_at' => $result->fetchedAt->format(DATE_ATOM),
                    'expires_at' => $result->expiresAt->format(DATE_ATOM),
                ];
            } catch (\Throwable $e) {
                $failed = true;
                $results[] = [
                    'provider' => $provider,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => ! $failed, 'results' => $results]);

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($results as $result) {
            if ($result['success']) {
                $output->writeln(sprintf(
                    '<info>%s: refreshed %d models from %s.</info>',
                    $result['provider'],
                    $result['model_count'],
                    $result['source'],
                ));
            } else {
                $output->writeln(sprintf('<error>%s: %s</error>', $result['provider'], $result['error']));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
