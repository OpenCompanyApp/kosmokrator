<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpResourcesCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container, private readonly bool $read)
    {
        parent::__construct($read ? 'mcp:resource' : 'mcp:resources');
        $this->setDescription($read ? 'Read an MCP resource' : 'List MCP resources');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addArgument('uri', InputArgument::OPTIONAL, 'Resource URI')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass MCP trust and read permission policy')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        try {
            $serverName = (string) $input->getArgument('server');
            $server = $store->get($serverName);
            if ($server === null) {
                throw new \RuntimeException("Unknown MCP server: {$serverName}");
            }
            $this->container->make(McpPermissionEvaluator::class)->assertReadAllowed($server, (bool) $input->getOption('force'));
            $client = $this->container->make(McpClientManager::class)->client($serverName);
            $data = $this->read
                ? $client->readResource((string) $input->getArgument('uri'))
                : $client->listResources();
            $this->writeJson($output, ['success' => true, $this->read ? 'resource' : 'resources' => $data]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }
}
