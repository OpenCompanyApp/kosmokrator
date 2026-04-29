<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:gateway:export', description: 'Export a Claude-compatible KosmoKrator MCP gateway server config')]
final class McpGatewayExportCommand extends Command
{
    use InteractsWithMcpOutput;
    use ResolvesMcpGatewayOptions;

    protected function configure(): void
    {
        $this->configureGatewayOptions($this)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'MCP server name', 'kosmokrator')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Wrap output in a JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) ($input->getOption('name') ?: 'kosmokrator');
        $config = ['mcpServers' => [$name => $this->gatewayServerConfig($input)]];

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'config' => $config]);
        } else {
            $this->writeJson($output, $config);
        }

        return Command::SUCCESS;
    }
}
