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

#[AsCommand(name: 'providers:doctor', description: 'Diagnose provider model inventory and configuration')]
final class ProvidersDoctorCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider ID')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model ID to validate')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Try a live provider model refresh')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = (string) $input->getArgument('provider');
        $model = is_string($input->getOption('model')) ? trim($input->getOption('model')) : '';
        $catalog = $this->container->make(ProviderCatalog::class);
        $discovery = $this->container->make(ModelDiscoveryService::class);
        $definition = $catalog->provider($provider);
        $issues = [];
        $live = null;

        if ($definition === null) {
            return $this->fail($input, $output, "Unknown provider [{$provider}].");
        }

        if ($definition->authMode === 'api_key' && trim($catalog->apiKey($provider)) === '') {
            $issues[] = "Provider [{$provider}] is missing an API key.";
        }

        if (! $discovery->canDiscoverLive($provider)) {
            $issues[] = "Provider [{$provider}] has no supported live model discovery endpoint.";
        }

        if ($model !== '' && ! $catalog->supportsModel($provider, $model)) {
            $issues[] = "Model [{$model}] is not currently advertised by provider [{$provider}].";
        }

        if ($input->getOption('live') && $discovery->canDiscoverLive($provider)) {
            try {
                $live = $discovery->refresh($provider);
            } catch (\Throwable $e) {
                $issues[] = 'Live model refresh failed: '.$e->getMessage();
            }
        }

        $payload = [
            'success' => $issues === [],
            'provider' => $provider,
            'auth_status' => $catalog->authStatus($provider),
            'driver' => $definition->driver,
            'url' => $definition->url,
            'free_text_model' => $definition->freeTextModel,
            'model_source' => $definition->modelSource,
            'model_fetched_at' => $definition->modelFetchedAt,
            'model_inventory_fresh' => $definition->modelInventoryFresh,
            'model_inventory_error' => $definition->modelInventoryError,
            'model_count' => count($definition->models),
            'discovery_endpoint' => $discovery->discoveryEndpoint($provider),
            'live_refresh' => $live?->toArray(),
            'issues' => $issues,
            'next_commands' => array_values(array_filter([
                "kosmo providers:models {$provider} --json",
                $discovery->canDiscoverLive($provider) ? "kosmo providers:models {$provider} --live --json" : null,
                $definition->authMode === 'api_key' ? "kosmo providers:configure {$provider} --api-key-env YOUR_ENV --json" : null,
            ])),
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $payload);

            return $payload['success'] ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln("Provider: {$provider}");
        $output->writeln("Auth: {$payload['auth_status']}");
        $output->writeln("Inventory: {$payload['model_source']} · {$payload['model_count']} models");
        foreach ($issues as $issue) {
            $output->writeln("<error>{$issue}</error>");
        }

        return $payload['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => false, 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return Command::FAILURE;
    }
}
