<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Secrets;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SecretStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'secrets:list', description: 'List managed secret statuses')]
final class SecretsListCommand extends Command
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
        $secrets = $this->container->make(SecretStore::class)->list();
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'secrets' => $secrets]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Key', 'Configured', 'Masked'])
            ->setRows(array_map(static fn (array $secret): array => [
                $secret['key'],
                $secret['configured'] ? 'yes' : 'no',
                $secret['masked'],
            ], $secrets))
            ->render();

        return Command::SUCCESS;
    }
}
