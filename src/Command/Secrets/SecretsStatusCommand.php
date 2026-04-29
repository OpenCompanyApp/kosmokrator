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

#[AsCommand(name: 'secrets:status', description: 'Show status for one managed secret')]
final class SecretsStatusCommand extends Command
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
        $status = $this->container->make(SecretStore::class)->status((string) $input->getArgument('key'));
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'secret' => $status]);
        } else {
            $output->writeln($status['key'].': '.($status['configured'] ? 'configured' : 'missing'));
        }

        return Command::SUCCESS;
    }
}
