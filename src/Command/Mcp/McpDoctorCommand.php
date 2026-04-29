<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:doctor', description: 'Diagnose MCP headless readiness')]
final class McpDoctorCommand extends Command
{
    use InteractsWithMcpOutput;

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
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $runtime = $this->container->make(McpRuntime::class);
        $servers = [];
        foreach ($store->effectiveServers() as $server) {
            $servers[$server->name] = $runtime->serverRow($server);
        }

        $data = [
            'success' => true,
            'project_config' => $store->projectPath(),
            'global_config' => $store->globalPath(),
            'sources' => array_map(static fn (array $source): array => [
                'source' => $source['source'],
                'path' => $source['path'],
                'schema' => $source['schema'],
                'servers' => array_keys($source['servers']),
            ], $store->readSources()),
            'servers' => $servers,
            'next_commands' => [
                'kosmokrator mcp:list --json',
                'kosmokrator mcp:tools SERVER --json',
                'kosmokrator mcp:schema SERVER.TOOL --json',
                'kosmokrator mcp:call SERVER.TOOL --json',
                'kosmokrator mcp:lua --eval \'dump(mcp.servers())\' --json',
            ],
        ];

        $this->writeJson($output, $data);

        return Command::SUCCESS;
    }
}
