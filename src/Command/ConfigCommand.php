<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\EffectiveSetting;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\SettingValueFormatter;
use Kosmokrator\Settings\SettingValueParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inspects and updates KosmoKrator configuration (show, get, set, unset, edit).
 */
#[AsCommand(name: 'config', description: 'Inspect and update KosmoKrator configuration')]
final class ConfigCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show|get|set|unset|edit|paths', 'show')
            ->addArgument('key', InputArgument::OPTIONAL, 'Setting key')
            ->addArgument('value', InputArgument::OPTIONAL, 'New setting value')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Target the global config file')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Target the project config file')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider context for dynamic model validation')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $catalog = $this->container->make(SettingsCatalog::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

        $action = (string) $input->getArgument('action');
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');
        $scope = $input->getOption('global') ? 'global' : 'project';

        $json = (bool) $input->getOption('json');

        return match ($action) {
            'show' => $this->show($settings, $schema, $output, is_string($key) ? $key : null, $json),
            'get' => $this->get($settings, $schema, $output, is_string($key) ? $key : null, $json),
            'set' => $this->set(
                $settings,
                $schema,
                $catalog,
                $output,
                is_string($key) ? $key : null,
                is_scalar($value) || $value === null ? $value : null,
                $scope,
                is_string($input->getOption('provider')) ? $input->getOption('provider') : null,
                $json,
            ),
            'unset' => $this->unset($settings, $schema, $output, is_string($key) ? $key : null, $scope, $json),
            'edit' => $this->edit($settings, $output, $scope),
            'paths' => $this->paths($settings, $output, $json),
            default => Command::INVALID,
        };
    }

    /**
     * Lists all settings with their resolved values, sources, and effects.
     */
    private function show(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $filterKey, bool $json = false): int
    {
        $rows = [];

        if ($filterKey !== null && $filterKey !== '') {
            $effective = $settings->resolve($filterKey);
            if ($effective === null) {
                if ($json) {
                    $this->writeJson($output, ['success' => false, 'error' => "Unknown setting [{$filterKey}]"]);

                    return Command::FAILURE;
                }

                $output->writeln("<error>Unknown setting [{$filterKey}]</error>");

                return Command::FAILURE;
            }

            $rows[] = $this->configRow($effective);
        } else {
            foreach ($schema->definitions() as $definition) {
                $effective = $settings->resolve($definition->id);
                if ($effective === null) {
                    continue;
                }

                $rows[] = $this->configRow($effective);
            }
        }

        if ($json) {
            $this->writeJson($output, ['success' => true, 'settings' => $rows]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Key', 'Value', 'Source', 'Effect'])
            ->setRows(array_map(static fn (array $row): array => [
                $row['id'],
                $row['display_value'],
                $row['source'],
                $row['effect'],
            ], $rows))
            ->render();

        return Command::SUCCESS;
    }

    /**
     * Prints the raw resolved value of a single setting key.
     */
    private function get(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $key, bool $json): int
    {
        if ($key === null || $key === '') {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => 'Provide a setting key.']);

                return Command::INVALID;
            }

            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        $effective = $settings->resolve($key);
        if ($effective === null) {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown setting [{$key}]"]);

                return Command::FAILURE;
            }

            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        if ($json) {
            $this->writeJson($output, ['success' => true, 'setting' => $this->configRow($effective)]);
        } else {
            $output->writeln(SettingValueFormatter::display($effective->value));
        }

        return Command::SUCCESS;
    }

    /**
     * Persists a setting value to the given scope (project or global config).
     */
    private function set(SettingsManager $settings, SettingsSchema $schema, SettingsCatalog $catalog, OutputInterface $output, ?string $key, mixed $value, string $scope, ?string $provider, bool $json): int
    {
        if ($key === null || $key === '') {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => 'Provide a setting key.']);

                return Command::INVALID;
            }

            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        $definition = $schema->definition($key);
        if ($definition === null) {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown setting [{$key}]"]);

                return Command::FAILURE;
            }

            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        try {
            $parsed = (new SettingValueParser)->parse($definition, $value);
            $this->validateChoice($definition->id, $parsed, $catalog, $provider);
            $settings->set($definition->id, $parsed, $scope);
        } catch (\Throwable $e) {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        if ($json) {
            $this->writeJson($output, [
                'success' => true,
                'scope' => $scope,
                'key' => $definition->id,
                'written_value' => $parsed,
                'written_display_value' => SettingValueFormatter::display($parsed),
                'effective_setting' => $this->configRow($settings->resolve($definition->id)),
                'setting' => $this->configRow($settings->resolve($definition->id)),
            ]);
        } else {
            $output->writeln("<info>Saved {$definition->id} to {$scope} config.</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * Removes a setting override from the given scope.
     */
    private function unset(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $key, string $scope, bool $json): int
    {
        if ($key === null || $key === '') {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => 'Provide a setting key.']);

                return Command::INVALID;
            }

            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        $definition = $schema->definition($key);
        if ($definition === null) {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown setting [{$key}]"]);

                return Command::FAILURE;
            }

            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        $settings->delete($definition->id, $scope);
        if ($json) {
            $this->writeJson($output, ['success' => true, 'key' => $definition->id, 'scope' => $scope]);
        } else {
            $output->writeln("<info>Removed {$definition->id} from {$scope} config.</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * Opens the config file in the user's $EDITOR (defaults to vi).
     */
    private function edit(SettingsManager $settings, OutputInterface $output, string $scope): int
    {
        $path = $scope === 'project'
            ? $settings->projectConfigPath()
            : $settings->globalConfigPath();

        if ($path === null) {
            $output->writeln('<error>No project config path is available in the current directory.</error>');

            return Command::FAILURE;
        }

        $editor = getenv('EDITOR') ?: 'vi';
        passthru($editor.' '.escapeshellarg($path));
        $output->writeln("<info>Closed {$path}</info>");

        return Command::SUCCESS;
    }

    private function paths(SettingsManager $settings, OutputInterface $output, bool $json): int
    {
        $paths = [
            'global' => $settings->globalConfigPath(),
            'project' => $settings->projectConfigPath(),
        ];

        if ($json) {
            $this->writeJson($output, ['success' => true, 'paths' => $paths]);
        } else {
            (new Table($output))
                ->setHeaders(['Scope', 'Path'])
                ->setRows([['global', $paths['global']], ['project', $paths['project'] ?? 'unavailable']])
                ->render();
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function configRow(?EffectiveSetting $effective): array
    {
        if ($effective === null) {
            return [];
        }

        return [
            'id' => $effective->id,
            'value' => $effective->value,
            'display_value' => SettingValueFormatter::display($effective->value),
            'source' => $effective->source,
            'scope' => $effective->scope,
            'effect' => $effective->definition->effect,
            'type' => $effective->definition->type,
            'path' => $effective->definition->path,
        ];
    }

    private function validateChoice(string $id, mixed $value, SettingsCatalog $catalog, ?string $provider = null): void
    {
        $options = $catalog->options($id, $provider === null ? [] : ['provider' => $provider]);
        $values = array_values(array_filter(
            array_map(static fn (array $option): string => (string) ($option['value'] ?? ''), $options),
            static fn (string $option): bool => $option !== '*' && $option !== '',
        ));

        if ($values !== [] && is_scalar($value) && ! in_array((string) $value, $values, true)) {
            throw new \InvalidArgumentException("Invalid value [{$value}] for [{$id}]. Allowed: ".implode(', ', $values));
        }
    }
}
