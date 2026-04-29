<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:list', description: 'List configured MCP servers')]
final class McpListCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include disabled servers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $runtime = $this->container->make(McpRuntime::class);
        $servers = array_map(
            fn ($server): array => $runtime->serverRow($server),
            $store->effectiveServers((bool) $input->getOption('all')),
        );

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'servers' => $servers,
                'sources' => array_map(
                    static fn (array $source): array => [
                        'source' => $source['source'],
                        'path' => $source['path'],
                        'schema' => $source['schema'],
                        'servers' => array_keys($source['servers']),
                    ],
                    $store->readSources(),
                ),
            ]);

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($servers as $server) {
            $rows[] = [$server['name'], $server['type'], $server['enabled'] ? 'yes' : 'no', $server['source'], $server['path']];
        }
        (new Table($output))->setHeaders(['Name', 'Type', 'Enabled', 'Source', 'Path'])->setRows($rows)->render();

        return Command::SUCCESS;
    }
}
