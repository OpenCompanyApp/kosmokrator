<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:schema', description: 'Print the JSON input schema for an integration function')]
final class IntegrationSchemaCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('function', InputArgument::REQUIRED, 'provider.function');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('function');
        $catalog = $this->container->make(IntegrationCatalog::class);
        $function = $catalog->get($name);
        if ($function === null) {
            $this->writeJson($output, ['success' => false, 'error' => "Unknown integration function: {$name}"]);

            return Command::FAILURE;
        }

        $this->writeJson($output, $catalog->hydrate($function)->inputSchema());

        return Command::SUCCESS;
    }
}
