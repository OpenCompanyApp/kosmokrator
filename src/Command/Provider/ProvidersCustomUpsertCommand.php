<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderConfigurator;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:custom:upsert', description: 'Create or update a custom provider')]
final class ProvidersCustomUpsertCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Custom provider ID')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Driver', 'openai-compatible')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Base URL')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Auth mode', 'api_key')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model ID')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Provider label')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context window')
            ->addOption('max-output', null, InputOption::VALUE_REQUIRED, 'Max output tokens')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE, 'Read API key from stdin')
            ->addOption('api-key-env', null, InputOption::VALUE_REQUIRED, 'Read API key from an environment variable')
            ->addOption('stdin-json', null, InputOption::VALUE_NONE, 'Read provider payload from stdin')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->container->make(SettingsManager::class)->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

        try {
            $payload = $input->getOption('stdin-json') ? $this->stdinJson() : [];
            $id = (string) ($payload['id'] ?? $input->getArgument('id') ?? '');
            $definition = is_array($payload['definition'] ?? null) ? $payload['definition'] : [
                'label' => $input->getOption('label') ?: $id,
                'driver' => $input->getOption('driver'),
                'auth' => $input->getOption('auth'),
                'url' => $input->getOption('url') ?? '',
                'model' => $input->getOption('model') ?? '',
                'context' => $input->getOption('context') ?? 0,
                'max_output' => $input->getOption('max-output') ?? 0,
            ];
            $result = $this->container->make(ProviderConfigurator::class)->upsertCustomProvider(
                id: $id,
                definition: $definition,
                apiKey: is_string($payload['api_key'] ?? null) ? $payload['api_key'] : $this->resolveSecretOption(
                    inline: is_string($input->getOption('api-key')) ? $input->getOption('api-key') : null,
                    stdin: (bool) $input->getOption('api-key-stdin'),
                    env: is_string($input->getOption('api-key-env')) ? $input->getOption('api-key-env') : null,
                ),
                scope: $this->scope($input, is_string($payload['scope'] ?? null) ? $payload['scope'] : null),
            );
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true] + $result);
        } else {
            $output->writeln('<info>Saved custom provider '.$result['id'].'.</info>');
        }

        return Command::SUCCESS;
    }

    private function resolveSecretOption(?string $inline, bool $stdin, ?string $env): ?string
    {
        if ($stdin) {
            $value = trim((string) stream_get_contents(STDIN));

            return $value === '' ? null : $value;
        }

        if ($env !== null && $env !== '') {
            $value = getenv($env);

            return is_string($value) && $value !== '' ? $value : null;
        }

        return $inline;
    }
}
