<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:list', description: 'List headless-configurable settings')]
final class SettingsListCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(SettingsCatalog::class);
        $schema = $this->container->make(SettingsSchema::class);
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $category = $input->getOption('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        $categories = $schema->categoryLabels();
        if ($category !== null && ! array_key_exists($category, $categories)) {
            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => false,
                    'error' => "Unknown category [{$category}].",
                    'categories' => array_keys($categories),
                ]);
            } else {
                $output->writeln("<error>Unknown category [{$category}].</error>");
            }

            return Command::FAILURE;
        }
        $settings = $catalog->settings($category);

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'categories' => array_map(
                    static fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
                    array_keys($categories),
                    array_values($categories),
                ),
                'settings' => $settings,
                'paths' => $catalog->paths(),
            ]);

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $setting): array => [
                $setting['id'],
                $setting['display_value'],
                $setting['source'],
                $setting['type'],
                $setting['effect'],
            ],
            $settings,
        );

        (new Table($output))
            ->setHeaders(['Key', 'Value', 'Source', 'Type', 'Effect'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
