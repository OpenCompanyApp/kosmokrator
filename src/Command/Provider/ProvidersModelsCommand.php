<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ModelDiscovery\ModelDiscoveryService;
use Kosmokrator\LLM\ProviderCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:models', description: 'List models for a provider')]
final class ProvidersModelsCommand extends Command
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
            ->addOption('live', null, InputOption::VALUE_NONE, 'Refresh models from the provider API before listing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = (string) $input->getArgument('provider');
        $catalog = $this->container->make(ProviderCatalog::class);
        $definition = $catalog->provider($provider);
        if ($definition === null) {
            return $this->fail($input, $output, "Unknown provider [{$provider}].");
        }

        $liveResult = null;
        if ($input->getOption('live')) {
            try {
                $liveResult = $this->container->make(ModelDiscoveryService::class)->refresh($provider);
                $definition = $catalog->provider($provider) ?? $definition;
            } catch (\Throwable $e) {
                return $this->fail($input, $output, $e->getMessage());
            }
        }

        $models = array_map(static fn ($model): array => [
            'id' => $model->id,
            'display_name' => $model->displayName,
            'context_window' => $model->contextWindow,
            'max_output' => $model->maxOutput,
            'thinking' => $model->thinking,
            'status' => $model->status,
            'source' => $model->source,
            'input_modalities' => $model->inputModalities,
            'output_modalities' => $model->outputModalities,
        ], $definition->models);

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'provider' => $provider,
                'free_text_model' => $definition->freeTextModel,
                'default_model' => $definition->defaultModel,
                'model_source' => $definition->modelSource,
                'model_fetched_at' => $definition->modelFetchedAt,
                'model_inventory_fresh' => $definition->modelInventoryFresh,
                'model_inventory_error' => $definition->modelInventoryError,
                'live_refreshed' => $liveResult !== null,
                'models' => $models,
            ]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Model', 'Context', 'Max Output', 'Thinking', 'Source', 'Status'])
            ->setRows(array_map(static fn (array $model): array => [
                $model['id'],
                $model['context_window'],
                $model['max_output'],
                $model['thinking'] ? 'yes' : 'no',
                $model['source'],
                $model['status'] ?? '',
            ], $models))
            ->render();

        $output->writeln(sprintf(
            '<comment>Inventory: %s%s</comment>',
            $definition->modelSource,
            $definition->modelFetchedAt !== null ? ' · '.$definition->modelFetchedAt : '',
        ));

        return Command::SUCCESS;
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
