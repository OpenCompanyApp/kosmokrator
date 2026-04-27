<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SettingsCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:get', description: 'Get one setting')]
final class SettingsGetCommand extends Command
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(SettingsCatalog::class);
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $setting = $catalog->setting((string) $input->getArgument('key'));

        if ($setting === null) {
            $message = 'Unknown setting ['.$input->getArgument('key').'].';
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $message]);
            } else {
                $output->writeln("<error>{$message}</error>");
            }

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'setting' => $setting]);
        } else {
            $output->writeln((string) $setting['display_value']);
        }

        return Command::SUCCESS;
    }
}
