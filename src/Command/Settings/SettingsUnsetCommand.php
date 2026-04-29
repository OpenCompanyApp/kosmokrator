<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:unset', description: 'Remove a setting override')]
final class SettingsUnsetCommand extends Command
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
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

        $key = (string) $input->getArgument('key');
        $definition = $schema->definition($key);
        if ($definition === null) {
            $message = "Unknown setting [{$key}].";
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $message]);
            } else {
                $output->writeln("<error>{$message}</error>");
            }

            return Command::FAILURE;
        }

        $scope = $this->scope($input);
        $settings->delete($definition->id, $scope);

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'key' => $definition->id, 'scope' => $scope]);
        } else {
            $output->writeln('<info>Removed '.$definition->id.' from '.$scope.' config.</info>');
        }

        return Command::SUCCESS;
    }
}
