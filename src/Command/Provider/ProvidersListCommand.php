<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:list', description: 'List configured LLM providers')]
final class ProvidersListCommand extends Command
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
        $catalog = $this->container->make(ProviderCatalog::class);
        $providers = array_map(fn ($provider): array => [
            'id' => $provider->id,
            'label' => $provider->label,
            'description' => $provider->description,
            'auth_mode' => $provider->authMode,
            'auth_status' => $catalog->authStatus($provider->id),
            'source' => $provider->source,
            'driver' => $provider->driver,
            'url' => $provider->url,
            'default_model' => $provider->defaultModel,
            'model_count' => count($provider->models),
            'model_source' => $provider->modelSource,
            'model_fetched_at' => $provider->modelFetchedAt,
            'model_inventory_fresh' => $provider->modelInventoryFresh,
            'model_inventory_error' => $provider->modelInventoryError,
            'free_text_model' => $provider->freeTextModel,
            'input_modalities' => $provider->inputModalities,
            'output_modalities' => $provider->outputModalities,
        ], $catalog->providers());

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'providers' => $providers]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Auth', 'Status', 'Models', 'Source'])
            ->setRows(array_map(static fn (array $provider): array => [
                $provider['id'],
                $provider['auth_mode'],
                $provider['auth_status'],
                ($provider['free_text_model'] ? 'free text' : (string) $provider['model_count']).' · '.$provider['model_source'],
                $provider['source'],
            ], $providers))
            ->render();

        return Command::SUCCESS;
    }
}
