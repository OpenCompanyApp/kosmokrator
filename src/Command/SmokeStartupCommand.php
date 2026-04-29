<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\Web\WebProviderRegistry;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'smoke:startup', description: 'Run offline startup checks for CI and release artifacts')]
final class SmokeStartupCommand extends Command
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
        $checks = [];
        $errors = [];

        $this->check('container', $checks, $errors, fn (): array => [
            'base_path' => $this->container->make('path.base'),
            'config_path' => $this->container->make('path.config'),
        ]);

        $this->check('core_services', $checks, $errors, fn (): array => [
            'database' => $this->container->make(SessionDatabase::class)::class,
            'provider_catalog' => count($this->container->make(ProviderCatalog::class)->providers()),
            'settings' => count($this->container->make(SettingsCatalog::class)->settings()),
            'llm_client' => $this->container->make(LlmClientInterface::class)::class,
        ]);

        $this->check('tools', $checks, $errors, function (): array {
            $tools = array_keys($this->container->make(ToolRegistry::class)->all());
            sort($tools, SORT_STRING);

            return [
                'count' => count($tools),
                'required_present' => $this->requiredPresent($tools, [
                    'file_read',
                    'file_write',
                    'file_edit',
                    'apply_patch',
                    'bash',
                    'web_search',
                    'web_fetch_external',
                    'web_extract',
                    'web_crawl',
                ]),
            ];
        });

        $this->check('integrations', $checks, $errors, function (): array {
            $registry = $this->container->make(ToolProviderRegistry::class);
            $manager = $this->container->make(IntegrationManager::class);
            $catalog = $this->container->make(IntegrationCatalog::class);

            return [
                'registered_providers' => count($registry->all()),
                'discoverable_providers' => count($manager->getDiscoverableProviders()),
                'locally_runnable_providers' => count($manager->getLocallyRunnableProviders()),
                'functions' => count($catalog->functions()),
                'package_autoload' => $this->integrationPackageAutoloadStatus(),
            ];
        });

        $this->check('web', $checks, $errors, fn (): array => [
            'providers' => count($this->container->make(WebProviderRegistry::class)->statuses()),
        ]);

        $this->check('mcp', $checks, $errors, fn (): array => [
            'sources' => count($this->container->make(McpConfigStore::class)->readSources()),
            'effective_servers' => count($this->container->make(McpConfigStore::class)->effectiveServers()),
        ]);

        $this->check('commands', $checks, $errors, function (): array {
            $application = $this->getApplication();
            if ($application === null) {
                throw new \RuntimeException('Console application is not attached to smoke command.');
            }

            $commands = $application->all();
            $invalid = [];
            foreach ($commands as $name => $command) {
                if ($name === '' || $command->getName() === null) {
                    $invalid[] = $name;
                }

                $command->getSynopsis();
            }

            if ($invalid !== []) {
                throw new \RuntimeException('Invalid command names: '.implode(', ', $invalid));
            }

            return [
                'count' => count($commands),
                'has_agent' => $application->has('agent'),
                'has_smoke_startup' => $application->has('smoke:startup'),
            ];
        });

        $payload = [
            'success' => $errors === [],
            'checks' => $checks,
            'errors' => $errors,
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $payload);
        } else {
            $output->writeln($errors === [] ? 'Startup smoke checks passed.' : 'Startup smoke checks failed.');
            foreach ($errors as $error) {
                $output->writeln('- '.$error['check'].': '.$error['message']);
            }
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @param  list<array{check: string, message: string}>  $errors
     * @param  callable(): array<string, mixed>  $callback
     */
    private function check(string $name, array &$checks, array &$errors, callable $callback): void
    {
        try {
            $checks[$name] = ['success' => true] + $callback();
        } catch (\Throwable $e) {
            $checks[$name] = ['success' => false];
            $errors[] = ['check' => $name, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $required
     * @return array<string, bool>
     */
    private function requiredPresent(array $available, array $required): array
    {
        $present = [];
        foreach ($required as $name) {
            $present[$name] = in_array($name, $available, true);
        }

        return $present;
    }

    /**
     * @return array<string, bool>
     */
    private function integrationPackageAutoloadStatus(): array
    {
        $classes = [
            'core_registry' => ToolProviderRegistry::class,
            'clickup_provider' => 'OpenCompany\\Integrations\\ClickUp\\ClickUpServiceProvider',
            'coingecko_provider' => 'OpenCompany\\Integrations\\CoinGecko\\CoinGeckoServiceProvider',
            'plane_provider' => 'OpenCompany\\Integrations\\Plane\\PlaneServiceProvider',
            'plausible_provider' => 'OpenCompany\\Integrations\\Plausible\\PlausibleServiceProvider',
        ];

        $status = [];
        foreach ($classes as $label => $class) {
            $status[$label] = class_exists($class);
            if (! $status[$label]) {
                throw new \RuntimeException("Integration class is not autoloadable: {$class}");
            }
        }

        return $status;
    }
}
