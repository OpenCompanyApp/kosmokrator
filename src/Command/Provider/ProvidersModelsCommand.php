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

        $models = array_map(static fn ($model): array => [
            'id' => $model->id,
            'display_name' => $model->displayName,
            'context_window' => $model->contextWindow,
            'max_output' => $model->maxOutput,
            'thinking' => $model->thinking,
            'status' => $model->status,
            'input_modalities' => $model->inputModalities,
            'output_modalities' => $model->outputModalities,
        ], $definition->models);

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'provider' => $provider,
                'free_text_model' => $definition->freeTextModel,
                'default_model' => $definition->defaultModel,
                'models' => $models,
            ]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Model', 'Context', 'Max Output', 'Thinking', 'Status'])
            ->setRows(array_map(static fn (array $model): array => [
                $model['id'],
                $model['context_window'],
                $model['max_output'],
                $model['thinking'] ? 'yes' : 'no',
                $model['status'] ?? '',
            ], $models))
            ->render();

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
