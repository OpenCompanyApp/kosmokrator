<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:search', description: 'Search integration functions')]
final class IntegrationSearchCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Search query')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->container->make(IntegrationCatalog::class)->search((string) $input->getArgument('query'));

        if ($input->getOption('json')) {
            $this->writeJson($output, array_map(static fn ($function): array => $function->toArray(), $results));

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Function', 'Operation', 'Status', 'Description'])
            ->setRows(array_map(static fn ($function): array => [
                $function->fullName(),
                $function->operation,
                $function->active ? 'active' : 'inactive',
                trim((string) preg_replace('/\s+/', ' ', $function->description)),
            ], $results))
            ->render();

        return Command::SUCCESS;
    }
}
