<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Settings\SettingsCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:options', description: 'Show valid options for a setting')]
final class SettingsOptionsCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Setting key')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider context for model settings')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(SettingsCatalog::class);
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $key = (string) $input->getArgument('key');
        $setting = $catalog->setting($key);
        if ($setting === null) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown setting [{$key}]."]);
            } else {
                $output->writeln("<error>Unknown setting [{$key}].</error>");
            }

            return Command::FAILURE;
        }

        $provider = $input->getOption('provider');
        if (is_string($provider) && $provider !== '') {
            $providerCatalog = $this->container->make(ProviderCatalog::class);
            if ($providerCatalog->provider($provider) === null) {
                if ($input->getOption('json')) {
                    $this->writeJson($output, [
                        'success' => false,
                        'error' => "Unknown provider [{$provider}].",
                    ]);
                } else {
                    $output->writeln("<error>Unknown provider [{$provider}].</error>");
                }

                return Command::FAILURE;
            }
        }

        $options = $catalog->options($key, is_string($provider) ? ['provider' => $provider] : []);
        $setting['options'] = $options;
        $setting['option_values'] = array_map(static fn (array $option): string => (string) ($option['value'] ?? ''), $options);
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'setting' => $setting, 'options' => $options]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Value', 'Label', 'Description'])
            ->setRows(array_map(static fn (array $option): array => [
                $option['value'] ?? '',
                $option['label'] ?? '',
                $option['description'] ?? '',
            ], $options))
            ->render();

        return Command::SUCCESS;
    }
}
