<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:gateway:install', description: 'Install a KosmoKrator MCP gateway entry into project .mcp.json')]
final class McpGatewayInstallCommand extends Command
{
    use InteractsWithMcpOutput;
    use ResolvesMcpGatewayOptions;

    public function __construct(private readonly McpConfigStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureGatewayOptions($this)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'MCP server name', 'kosmokrator')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store->setProjectRoot($this->projectRoot());
        $name = (string) ($input->getOption('name') ?: 'kosmokrator');
        $server = $this->gatewayServerConfig($input);
        $path = $this->store->writeServer(McpServerConfig::fromArray($name, $server), 'project');

        $data = [
            'success' => true,
            'server' => $name,
            'path' => $path,
            'config' => ['mcpServers' => [$name => $server]],
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $data);
        } else {
            $output->writeln("Installed KosmoKrator MCP gateway in {$path}");
        }

        return Command::SUCCESS;
    }
}
