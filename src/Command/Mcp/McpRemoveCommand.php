<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:remove', description: 'Remove an MCP server from mcp.json')]
final class McpRemoveCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Server name')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Remove from global mcp.json')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Remove from project .mcp.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $path = $store->removeServer((string) $input->getArgument('name'), $this->scope($input));
        $data = ['success' => true, 'server' => (string) $input->getArgument('name'), 'path' => $path];
        $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln("Removed {$data['server']} from {$path}");

        return Command::SUCCESS;
    }
}
