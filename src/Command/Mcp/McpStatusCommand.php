<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:status', description: 'Show MCP server status')]
final class McpStatusCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $runtime = $this->container->make(McpRuntime::class);
        $serverName = $input->getArgument('server');
        $servers = $store->effectiveServers();
        if (is_string($serverName) && $serverName !== '') {
            if (! isset($servers[$serverName])) {
                $data = ['success' => false, 'error' => "Unknown MCP server: {$serverName}"];
                $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln('<error>'.$data['error'].'</error>');

                return Command::FAILURE;
            }
            $servers = [$serverName => $servers[$serverName]];
        }

        $data = [];
        foreach ($servers as $name => $server) {
            $row = $runtime->serverRow($server);
            $row['status'] = $server->enabled ? 'configured' : 'disabled';
            $data[$name] = $row;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'servers' => $data]);
        } else {
            foreach ($data as $name => $row) {
                $output->writeln("{$name}: {$row['status']}");
            }
        }

        return Command::SUCCESS;
    }
}
