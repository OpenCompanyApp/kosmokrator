<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\IntegrationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:fields', description: 'Show credential fields for an integration provider')]
final class IntegrationFieldsCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Integration provider')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account alias', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providerName = (string) $input->getArgument('provider');
        $account = (string) ($input->getOption('account') ?: 'default');
        $manager = $this->container->make(IntegrationManager::class);
        $provider = $manager->getDiscoverableProviders()[$providerName] ?? null;

        if ($provider === null) {
            $message = "Unknown integration provider: {$providerName}";
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $message]);
            } else {
                $output->writeln("<error>{$message}</error>");
            }

            return Command::FAILURE;
        }

        $capabilities = $manager->capabilityMetadata($provider);
        $fields = array_map(function (array $field) use ($manager, $providerName, $account): array {
            $key = (string) ($field['key'] ?? '');
            $value = $key !== '' ? $manager->credentialValue($providerName, $key, $account === 'default' ? null : $account) : null;

            return [
                'key' => $key,
                'label' => (string) ($field['label'] ?? $key),
                'type' => (string) ($field['type'] ?? 'text'),
                'required' => (bool) ($field['required'] ?? false),
                'configured' => $value !== null && (! is_string($value) || trim($value) !== ''),
                'description' => (string) ($field['description'] ?? ''),
            ];
        }, $provider->credentialFields());

        $data = [
            'success' => true,
            'provider' => $providerName,
            'account' => $account,
            'accounts' => array_values(array_unique(array_merge(['default'], $manager->getAccounts($providerName)))),
            'cli_setup_supported' => $capabilities['cli_setup_supported'],
            'cli_runtime_supported' => $capabilities['cli_runtime_supported'],
            'auth_strategy' => $capabilities['auth_strategy'],
            'compatibility_summary' => $capabilities['compatibility_summary'],
            'fields' => $fields,
            'example' => $capabilities['cli_setup_supported']
                ? "kosmo integrations:configure {$providerName} --account={$account} --set key=value --enable --json"
                : null,
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $data);

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($fields as $field) {
            $rows[] = [
                $field['key'],
                $field['label'],
                $field['type'],
                $field['required'] ? 'yes' : 'no',
                $field['configured'] ? 'yes' : 'no',
            ];
        }

        (new Table($output))
            ->setHeaders(['Key', 'Label', 'Type', 'Required', 'Configured'])
            ->setRows($rows)
            ->render();
        if (is_string($data['example'])) {
            $output->writeln($data['example']);
        } else {
            $output->writeln('Headless credential setup is not supported for this integration yet.');
        }

        return Command::SUCCESS;
    }
}
