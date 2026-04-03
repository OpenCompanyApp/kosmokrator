<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config', description: 'Inspect and update KosmoKrator configuration')]
final class ConfigCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show|get|set|unset|edit', 'show')
            ->addArgument('key', InputArgument::OPTIONAL, 'Setting key')
            ->addArgument('value', InputArgument::OPTIONAL, 'New setting value')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Target the global config file')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Target the project config file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $settings->setProjectRoot(\Kosmokrator\Agent\InstructionLoader::gitRoot() ?? getcwd());

        $action = (string) $input->getArgument('action');
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');
        $scope = $input->getOption('global') ? 'global' : 'project';

        return match ($action) {
            'show' => $this->show($settings, $schema, $output, is_string($key) ? $key : null),
            'get' => $this->get($settings, $schema, $output, is_string($key) ? $key : null),
            'set' => $this->set($settings, $schema, $output, is_string($key) ? $key : null, is_scalar($value) || $value === null ? $value : null, $scope),
            'unset' => $this->unset($settings, $schema, $output, is_string($key) ? $key : null, $scope),
            'edit' => $this->edit($settings, $output, $scope),
            default => Command::INVALID,
        };
    }

    private function show(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $filterKey): int
    {
        $rows = [];

        if ($filterKey !== null && $filterKey !== '') {
            $effective = $settings->resolve($filterKey);
            if ($effective === null) {
                $output->writeln("<error>Unknown setting [{$filterKey}]</error>");

                return Command::FAILURE;
            }

            $rows[] = [$effective->id, (string) $effective->value, $effective->source, $effective->definition->effect];
        } else {
            foreach ($schema->definitions() as $definition) {
                $effective = $settings->resolve($definition->id);
                if ($effective === null) {
                    continue;
                }

                $rows[] = [$effective->id, (string) $effective->value, $effective->source, $effective->definition->effect];
            }
        }

        (new Table($output))
            ->setHeaders(['Key', 'Value', 'Source', 'Effect'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    private function get(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $key): int
    {
        if ($key === null || $key === '') {
            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        $effective = $settings->resolve($key);
        if ($effective === null) {
            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        $output->writeln((string) $effective->value);

        return Command::SUCCESS;
    }

    private function set(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $key, mixed $value, string $scope): int
    {
        if ($key === null || $key === '') {
            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        if ($schema->definition($key) === null) {
            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        $settings->set($key, $value ?? '', $scope);
        $output->writeln("<info>Saved {$key} to {$scope} config.</info>");

        return Command::SUCCESS;
    }

    private function unset(SettingsManager $settings, SettingsSchema $schema, OutputInterface $output, ?string $key, string $scope): int
    {
        if ($key === null || $key === '') {
            $output->writeln('<error>Provide a setting key.</error>');

            return Command::INVALID;
        }

        if ($schema->definition($key) === null) {
            $output->writeln("<error>Unknown setting [{$key}]</error>");

            return Command::FAILURE;
        }

        $settings->delete($key, $scope);
        $output->writeln("<info>Removed {$key} from {$scope} config.</info>");

        return Command::SUCCESS;
    }

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
}
