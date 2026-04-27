<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:doctor', description: 'Diagnose integration configuration and print next steps')]
final class IntegrationDoctorCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Integration provider')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->container->make(IntegrationManager::class);
        $catalog = $this->container->make(IntegrationCatalog::class);
        $providerFilter = $input->getArgument('provider');
        $providers = $manager->getDiscoverableProviders();

        if (is_string($providerFilter) && $providerFilter !== '') {
            $providers = isset($providers[$providerFilter]) ? [$providerFilter => $providers[$providerFilter]] : [];
        }

        $functionsByProvider = $catalog->byProvider();
        $data = [];
        foreach ($providers as $name => $provider) {
            $missing = [];
            $capabilities = $manager->capabilityMetadata($provider);
            foreach ($provider->credentialFields() as $field) {
                $key = (string) ($field['key'] ?? '');
                $value = $key !== '' ? $manager->credentialValue($name, $key) : null;
                if (($field['required'] ?? false) === true && ($value === null || (is_string($value) && trim($value) === ''))) {
                    $missing[] = $key;
                }
            }

            $configured = $manager->isConfiguredForActivation($name, $provider);
            $enabled = $manager->isEnabled($name);
            $functions = $functionsByProvider[$name] ?? [];
            $next = [];
            if ($missing !== [] && $capabilities['cli_setup_supported']) {
                $next[] = 'kosmokrator integrations:fields '.$name.' --json';
                $next[] = 'kosmokrator integrations:configure '.$name.' --set '.implode('=... --set ', $missing).'=... --json';
            }
            if (! $capabilities['cli_setup_supported']) {
                $next[] = 'kosmokrator integrations:docs '.$name;
            } elseif (! $enabled) {
                $next[] = 'kosmokrator integrations:configure '.$name.' --enable --json';
            }
            if ($configured && $enabled && $functions !== []) {
                $next[] = 'kosmokrator integrations:docs '.$functions[0]->fullName();
            }

            $data[$name] = [
                'provider' => $name,
                'locally_runnable' => $capabilities['cli_runtime_supported'],
                'cli_setup_supported' => $capabilities['cli_setup_supported'],
                'cli_runtime_supported' => $capabilities['cli_runtime_supported'],
                'auth_strategy' => $capabilities['auth_strategy'],
                'host_availability' => $capabilities['host_availability'],
                'runtime_requirements' => $capabilities['runtime_requirements'],
                'compatibility' => $capabilities['compatibility'],
                'compatibility_summary' => $capabilities['compatibility_summary'],
                'enabled' => $enabled,
                'configured' => $configured,
                'active' => $enabled && $configured && $capabilities['cli_runtime_supported'],
                'missing_credentials' => $missing,
                'accounts' => array_values(array_unique(array_merge(['default'], $manager->getAccounts($name)))),
                'permissions' => [
                    'read' => $manager->getPermission($name, 'read'),
                    'write' => $manager->getPermission($name, 'write'),
                ],
                'function_count' => count($functions),
                'example_functions' => array_slice(array_map(static fn ($function): string => $function->fullName(), $functions), 0, 5),
                'next_commands' => array_values(array_unique($next)),
            ];
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, is_string($providerFilter) && $providerFilter !== '' ? ($data[$providerFilter] ?? ['success' => false, 'error' => 'Unknown provider']) : $data);

            return $data === [] && is_string($providerFilter) && $providerFilter !== '' ? Command::FAILURE : Command::SUCCESS;
        }

        if ($data === []) {
            $output->writeln('<error>No matching integration providers.</error>');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($data as $name => $row) {
            $rows[] = [
                $name,
                $row['active'] ? 'yes' : 'no',
                $row['compatibility_summary'],
                implode(', ', $row['missing_credentials']),
                "read={$row['permissions']['read']} write={$row['permissions']['write']}",
                implode("\n", $row['next_commands']),
            ];
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Active', 'Compatibility', 'Missing Credentials', 'Permissions', 'Next'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
