<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Secrets;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SecretStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'secrets:unset', description: 'Remove a managed secret')]
final class SecretsUnsetCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Secret key')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->container->make(SecretStore::class)->unset((string) $input->getArgument('key'));
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'key' => (string) $input->getArgument('key')]);
        } else {
            $output->writeln('<info>Secret removed.</info>');
        }

        return Command::SUCCESS;
    }
}
