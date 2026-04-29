<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpEnableCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container, private readonly bool $enabled)
    {
        parent::__construct($enabled ? 'mcp:enable' : 'mcp:disable');
        $this->setDescription(($enabled ? 'Enable' : 'Disable').' an MCP server');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Server name')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global mcp.json')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project .mcp.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $path = $store->setEnabled((string) $input->getArgument('name'), $this->enabled, $this->scope($input));
        $data = ['success' => true, 'server' => (string) $input->getArgument('name'), 'enabled' => $this->enabled, 'path' => $path];
        $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln(($this->enabled ? 'Enabled ' : 'Disabled ').$data['server']);

        return Command::SUCCESS;
    }
}
