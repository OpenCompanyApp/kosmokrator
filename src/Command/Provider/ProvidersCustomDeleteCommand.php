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

#[AsCommand(name: 'providers:custom:delete', description: 'Delete a custom provider')]
final class ProvidersCustomDeleteCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Custom provider ID')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->container->make(SettingsManager::class)->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $id = (string) $input->getArgument('id');
        $scope = $this->scope($input);
        $this->container->make(ProviderConfigurator::class)->deleteCustomProvider($id, $scope);

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'id' => $id, 'scope' => $scope]);
        } else {
            $output->writeln("<info>Deleted custom provider {$id}.</info>");
        }

        return Command::SUCCESS;
    }
}
