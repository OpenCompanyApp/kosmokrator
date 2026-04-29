<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Web\WebProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:providers', description: 'List optional external web providers')]
final class WebProvidersCommand extends Command
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
        $providers = $this->container->make(WebProviderRegistry::class)->statuses();

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'providers' => $providers]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Enabled', 'Configured', 'Capabilities', 'Base URL'])
            ->setRows(array_map(static fn (array $provider): array => [
                $provider['name'],
                $provider['enabled'] ? 'yes' : 'no',
                $provider['configured'] ? 'yes' : 'no',
                implode(', ', $provider['capabilities']),
                $provider['base_url'] ?? '',
            ], $providers))
            ->render();

        return Command::SUCCESS;
    }
}
